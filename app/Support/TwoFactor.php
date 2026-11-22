<?php

namespace App\Support;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

final class TwoFactor
{
    public static function newSecret(): string
    {
        return (new Google2FA)->generateSecretKey(32);
    }

    public static function verify(?string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);

        return $secret !== null && preg_match('/^\d{6}$/', $code) && (new Google2FA)->verifyKey($secret, $code, 1);
    }

    public static function qrSvg(User $user, string $secret): string
    {
        $url = (new Google2FA)->getQRCodeUrl(config('app.name'), $user->email, $secret);
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd));

        return $writer->writeString($url);
    }

    /** @return array{plain: list<string>, hashed: list<string>} */
    public static function newRecoveryCodes(): array
    {
        $plain = collect(range(1, 8))->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();

        return ['plain' => $plain, 'hashed' => array_map(fn ($c) => hash('sha256', $c), $plain)];
    }

    public static function useRecoveryCode(User $user, string $code): bool
    {
        $hash = hash('sha256', Str::lower(trim($code)));
        $codes = $user->two_factor_recovery_codes ?? [];
        if (! in_array($hash, $codes, true)) {
            return false;
        }
        $user->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$hash]))])->save();

        return true;
    }
}
