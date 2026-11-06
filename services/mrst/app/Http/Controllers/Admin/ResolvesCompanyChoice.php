<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The registration forms offer a list of company names (AI research, link labels, registered companies)
 * plus "other". This turns the chosen entry into the company_name the controllers already validate.
 */
trait ResolvesCompanyChoice
{
    public const COMPANY_CHOICE_OTHER = '__other__';

    private function resolveCompanyChoice(Request $request): void
    {
        if (! $request->has('company_choice')) {
            return;
        }
        $choice = trim((string) $request->input('company_choice'));
        $typed = trim((string) $request->input('company_name_other'));
        // A typed name is only used with "other", so a name typed by mistake never silently loses to the list.
        if ($choice !== self::COMPANY_CHOICE_OTHER && $typed !== '') {
            throw ValidationException::withMessages(['company_name_other' => '手入力した企業名を使うときは「その他」を選んでください。']);
        }
        $request->merge(['company_name' => $choice === self::COMPANY_CHOICE_OTHER ? $typed : $choice]);
    }
}
