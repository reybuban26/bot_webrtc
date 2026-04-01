<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as FilamentLogin;

class Login extends FilamentLogin
{
    public function getView(): string
    {
        return 'filament.pages.auth.login';
    }
}
