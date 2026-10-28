<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\AppSettings;
use Illuminate\Support\Facades\Storage;

/**
 * Streams brand logos from the private "local" disk (PRD: branding + per-company
 * logos). Logos are stored off the webroot — same placement as ticket attachments —
 * so this is the only way their bytes reach the browser. Both routes are public
 * because the images appear on guest-facing shells (login, intake); they expose
 * nothing but a static image and return 404 for a missing or unset logo.
 */
class LogoController extends Controller
{
    public function branding()
    {
        $path = AppSettings::logo();

        abort_unless($path !== null && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    public function company(Company $company)
    {
        $path = $company->logo_path;

        abort_unless($path !== null && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }
}
