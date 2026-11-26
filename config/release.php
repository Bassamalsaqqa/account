<?php

use App\Support\ReleaseIdentity;

return ReleaseIdentity::read(base_path('VERSION'), base_path('bootstrap/cache/release.json'));
