<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MfaService
{
    /**
     * @return array{secret: string, otpauthUri: string, recoveryCodes: array<int, string>}
     */
    public function enroll(User $user): array
    {
        $user = $this->persistableUser($user);
        if ((bool) $user->isBreakGlass) {
            throw ValidationException::withMessages([
                'account' => ['Break-glass accounts cannot enroll MFA through the standard flow.'],
            ]);
        }

        $secret = $this->generateBase32Secret();
        $recoveryCodes = $this->generateRecoveryCodes();
        $user->forceFill([
            'mfaSecretEncrypted' => Crypt::encryptString($secret),
            'mfaConfirmedAt' => null,
            'mfaRecoveryCodeHashes' => array_map(fn (string $code): string => Hash::make($code), $recoveryCodes),
        ])->save();

        return [
            'secret' => $secret,
            'otpauthUri' => $this->otpauthUri($user, $secret),
            'recoveryCodes' => $recoveryCodes,
        ];
    }

    public function confirm(User $user, string $code): User
    {
        $user = $this->persistableUser($user);
        if (! $this->verifyTotp($user, $code)) {
            throw ValidationException::withMessages([
                'code' => ['The MFA code is invalid.'],
            ]);
        }

        $user->forceFill([
            'mfaRequired' => true,
            'mfaConfirmedAt' => now(),
        ])->save();

        return $user;
    }

    public function verifyStepUp(User $user, string $code): bool
    {
        $user = $this->persistableUser($user);
        if ($this->verifyTotp($user, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    public function totpForTest(User $user, ?int $timestamp = null): string
    {
        $user = $this->persistableUser($user);

        return $this->totp(Crypt::decryptString((string) $user->mfaSecretEncrypted), $timestamp ?? time());
    }

    private function persistableUser(User $user): User
    {
        return User::query()->findOrFail($user->id);
    }

    private function verifyTotp(User $user, string $code): bool
    {
        if ($user->mfaSecretEncrypted === null || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $secret = Crypt::decryptString((string) $user->mfaSecretEncrypted);
        $period = (int) config('mfa.totp_period_seconds', 30);
        $window = (int) config('mfa.totp_window', 1);
        $timestamp = time();

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->totp($secret, $timestamp + ($offset * $period)), $code)) {
                return true;
            }
        }

        return false;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $hashes = $user->mfaRecoveryCodeHashes ?? [];
        foreach ($hashes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$index]);
                $user->forceFill([
                    'mfaRecoveryCodeHashes' => array_values($hashes),
                ])->save();

                return true;
            }
        }

        return false;
    }

    private function totp(string $secret, int $timestamp): string
    {
        $counter = intdiv($timestamp, (int) config('mfa.totp_period_seconds', 30));
        $key = $this->base32Decode($secret);
        $binaryCounter = pack('N*', 0).pack('N*', $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad(
            (string) ($truncated % (10 ** (int) config('mfa.totp_digits', 6))),
            (int) config('mfa.totp_digits', 6),
            '0',
            STR_PAD_LEFT
        );
    }

    private function generateBase32Secret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 32; $i++) {
            $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $secret;
    }

    /**
     * @return array<int, string>
     */
    private function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < (int) config('mfa.recovery_code_count', 8); $i++) {
            $codes[] = 'idr-'.Str::lower(Str::random(10));
        }

        return $codes;
    }

    private function otpauthUri(User $user, string $secret): string
    {
        $issuer = (string) config('mfa.issuer', 'Idelium');

        return 'otpauth://totp/'.rawurlencode($issuer.':'.$user->email)
            .'?secret='.$secret
            .'&issuer='.rawurlencode($issuer)
            .'&digits='.(int) config('mfa.totp_digits', 6)
            .'&period='.(int) config('mfa.totp_period_seconds', 30);
    }

    private function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        $bits = '';
        foreach (str_split($secret) as $char) {
            $value = strpos($alphabet, $char);
            if ($value === false) {
                continue;
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $binary = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $binary .= chr(bindec($byte));
            }
        }

        return $binary;
    }
}
