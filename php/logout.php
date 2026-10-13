<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$page = login_page_for(auth()['type'] ?? null);
end_session();
redirect($page . '?notice=signedout');
