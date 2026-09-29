<?php

namespace App\Filament\Forms;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * Prompt 322 — the ONE field for typing a counter PIN in the panel (the user form's *PIN de mostrador* and *Repite el
 * PIN*, and *Probar PIN*).
 *
 * On live a PIN saved from the panel was not the PIN that was typed. It was a masked `type="password"` input with
 * `autocomplete="new-password"` in an account form: precisely what a Mac's or iPhone's password manager fills with a
 * saved password or a "strong password" suggestion, invisibly. So this is an ordinary TEXT input (password managers
 * leave those alone), numeric, told to be ignored by the common managers, masked only VISUALLY (`.csc-pin-mask`,
 * `-webkit-text-security` in the panel theme) with an eye to reveal it, and it drops anything but digits as it is typed
 * (Alpine mask). The server still refuses anything but 4–8 digits.
 */
final class PinInput
{
    public static function make(string $name): TextInput
    {
        return TextInput::make($name)
            ->autocomplete('off')
            ->inputMode('numeric')
            ->mask('99999999') // digits only, at most 8, as it is typed
            ->rule('digits_between:4,8')
            ->extraAttributes(['x-data' => '{ pinShown: false }']) // the input wrapper: shared with the reveal button
            ->extraInputAttributes([
                'pattern' => '[0-9]*',
                'data-1p-ignore' => true,
                'data-lpignore' => 'true',
                'data-bwignore' => true,
                'data-form-type' => 'other',
                'spellcheck' => 'false',
                'class' => 'csc-pin-mask',
                'x-bind:class' => "{ 'csc-pin-shown': pinShown }",
            ], merge: true)
            ->suffixAction(Action::make('reveal_'.str_replace('.', '_', $name))
                ->label(__('Mostrar u ocultar el PIN'))
                ->icon(Heroicon::OutlinedEye)
                ->iconButton()
                ->color('gray')
                ->alpineClickHandler('pinShown = ! pinShown'));
    }
}
