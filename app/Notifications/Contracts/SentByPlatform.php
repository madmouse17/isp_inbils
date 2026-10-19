<?php

namespace App\Notifications\Contracts;

/**
 * Marks a notification whose mail carries a credential: a link that sets or resets a password, or opens an account. The
 * platform mailer sends it, never a company's own SMTP server (CompanyMailChannel). A user can belong to several
 * companies, so the operator of one company's mail server would otherwise read a link into an account of another.
 *
 * Laravel's own ResetPassword and VerifyEmail are listed in CompanyMailChannel; every notification of the app that mints
 * a token or builds such a link implements this (IntegrationSecurityFixesTest fails for one that does not).
 */
interface SentByPlatform {}
