<?php

declare(strict_types=1);

// best-effort cleanup: file may not exist if a prior run failed
@unlink('/tmp/x');
