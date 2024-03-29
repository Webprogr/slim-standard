<?php


namespace App\Domain\Security\Service;


use App\Domain\Security\Data\RequestStatsData;
use App\Domain\Security\Exception\SecurityException;
use App\Domain\Settings;
use App\Infrastructure\Security\RequestFinderRepository;

class SecurityEmailChecker
{
    private array $securitySettings;
    private ?string $email;

    public function __construct(
        private SecurityCaptchaVerifier $captchaVerifier,
        private SecurityRequestFinder $requestFinder,
        private RequestFinderRepository $requestFinderRepository,
        Settings $settings
    ) {
        $this->securitySettings = $settings->get('security');
    }

    /**
     * Threat: Email abuse (sending a lot of emails may be costly)
     *
     * Throttle behaviour: Limit email sending
     * - After x amount of emails sent from ip or user they have 3 thresholds with
     *    different waiting times
     * - After the last threshold is reached, captcha is required for every email sent
     * - Limit applies to last [timespan]. If waited enough, users can send unrestricted emails again
     * - Globally there are two optional rules:
     *   1. Defined daily limit - after it is reached, captcha is required for every user
     *   2. Monthly limit - after it is reached, captcha is required for every user (mailgun resets after 1st)
     *
     * Perform email abuse check
     * - coming from the same ip address
     * - concerning a specific email address
     * - global email requests
     *
     * @param string $email
     * @param string|null $reCaptchaResponse
     */
    public function performEmailAbuseCheck(string $email, string|null $reCaptchaResponse = null): void
    {
        $validCaptcha = false;
        // reCAPTCHA verification
        if ($reCaptchaResponse !== null) {
            $validCaptcha = $this->captchaVerifier->verifyReCaptcha($reCaptchaResponse, SecurityException::USER_EMAIL);
        }
        // If captcha is valid the other security checks don't have to be made
        if ($validCaptcha !== true) {
            $this->email = $email;
            // Email checks (register, password recovery, other with email)
            $this->performEmailRequestsCheck(
                $this->requestFinder->retrieveIpStats(),
                $this->requestFinder->retrieveUserStats($email)
            );
            // Global email check
            $this->performGlobalEmailCheck();
        }
    }

    /**
     * Make email abuse check for requests coming from same ip
     * or concerning the same email address
     *
     * @param RequestStatsData $ipStats email request summary from actual ip address
     * @param RequestStatsData $userStats email request summary by concerning email / coming for same user
     * @throws SecurityException
     */
    private function performEmailRequestsCheck(RequestStatsData $ipStats, RequestStatsData $userStats): void
    {
        // Reverse order to compare fails longest delay first and then go down from there
        krsort($this->securitySettings['user_email_throttle']);
        // Fails on specific user or coming from specific IP
        foreach ($this->securitySettings['user_email_throttle'] as $requestLimit => $delay) {
            // If sent emails in the last given timespan is greater than the tolerated amount of requests with email per timespan
            if (
                $ipStats->sentEmails >= $requestLimit || $userStats->sentEmails >= $requestLimit
            ) {
                // Retrieve latest email sent for specific email or coming from ip
                $latestEmailRequestFromUser = $this->requestFinder->findLatestEmailRequestFromUserOrIp($this->email);

                $errMsg = 'Exceeded maximum of tolerated emails.'; // Change in SecurityServiceTest as well
                if (is_numeric($delay)) {
                    // created_at in seconds
                    $latest = (int)date('U', strtotime($latestEmailRequestFromUser->createdAt));

                    // Check that time is in the future by comparing actual time with forced delay + to latest request
                    if (time() < ($timeForNextRequest = $delay + $latest)) {
                        $remainingDelay = $timeForNextRequest - time();
                        throw new SecurityException($remainingDelay, SecurityException::USER_EMAIL, $errMsg);
                    }
                } elseif ($delay === 'captcha') {
                    throw new SecurityException($delay, SecurityException::USER_EMAIL, $errMsg);
                }
            }
        }
        // Revert krsort() done earlier to prevent unexpected behaviour later when working with ['login_throttle']
        ksort($this->securitySettings['login_throttle']);
    }

    /**
     * Protection against email abuse
     */
    private function performGlobalEmailCheck(): void
    {
        // Order of calls on getGlobalSentEmailAmount() matters in test. First daily and then monthly should be called

        // Check emails for daily threshold
        if (!empty($this->securitySettings['global_daily_email_threshold'])) {
            $sentEmailAmountInLastDay = $this->requestFinderRepository->getGlobalSentEmailAmount(1);
            // If sent emails exceed or equal the given threshold
            if ($sentEmailAmountInLastDay >= $this->securitySettings['global_daily_email_threshold']) {
                $msg = 'Maximum amount of unrestricted email sending daily reached site-wide.';
                throw new SecurityException('captcha', SecurityException::GLOBAL_EMAIL, $msg);
            }
        }

        // Check emails for monthly threshold
        if (!empty($this->securitySettings['global_monthly_email_threshold'])) {
            $sentEmailAmountInLastMonth = $this->requestFinderRepository->getGlobalSentEmailAmount(30);
            // If sent emails exceed or equal the given threshold
            if ($sentEmailAmountInLastMonth >= $this->securitySettings['global_monthly_email_threshold']) {
                $msg = 'Maximum amount of unrestricted email sending monthly reached site-wide.';
                throw new SecurityException('captcha', SecurityException::GLOBAL_EMAIL, $msg);
            }
        }
    }
}