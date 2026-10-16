<?php

namespace App\Support;

enum SecurityEvent
{
    case EmailChanged;
    case PasswordChanged;
    case PasswordReset;
    case TwoFactorEnabled;
    case TwoFactorDisabled;
    case RecoveryCodesRegenerated;
    case PasskeyAdded;
    case PasskeyRemoved;
    case AccountDeleted;

    /**
     * Get the mail subject describing the event.
     */
    public function subject(): string
    {
        return match ($this) {
            self::EmailChanged => __('The email address for your account was changed'),
            self::PasswordChanged => __('Your password was changed'),
            self::PasswordReset => __('Your password was reset'),
            self::TwoFactorEnabled => __('Two-factor authentication was enabled'),
            self::TwoFactorDisabled => __('Two-factor authentication was disabled'),
            self::RecoveryCodesRegenerated => __('Your recovery codes were regenerated'),
            self::PasskeyAdded => __('A passkey was added to your account'),
            self::PasskeyRemoved => __('A passkey was removed from your account'),
            self::AccountDeleted => __('Your account was deleted'),
        };
    }

    /**
     * Get the mail body describing the event.
     */
    public function message(): string
    {
        return match ($this) {
            self::EmailChanged => __('The email address for your account was changed. This notice was sent to your previous address.'),
            self::PasswordChanged => __('The password for your account was changed.'),
            self::PasswordReset => __('The password for your account was reset using a password reset link.'),
            self::TwoFactorEnabled => __('Two-factor authentication was enabled for your account.'),
            self::TwoFactorDisabled => __('Two-factor authentication was disabled for your account.'),
            self::RecoveryCodesRegenerated => __('The two-factor recovery codes for your account were regenerated. Your previous recovery codes no longer work.'),
            self::PasskeyAdded => __('A new passkey was added to your account.'),
            self::PasskeyRemoved => __('A passkey was removed from your account.'),
            self::AccountDeleted => __('Your account was deleted.'),
        };
    }
}
