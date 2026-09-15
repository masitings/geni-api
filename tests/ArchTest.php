<?php

declare(strict_types=1);

arch('schema reader stays framework free')
    ->expect('Geni\SchemaReader')
    ->not->toUse(['Illuminate', 'Geni\Laravel']);

arch('inference stays framework free')
    ->expect('Geni\Inference')
    ->not->toUse(['Illuminate', 'Geni\Laravel']);
