<?php

namespace Tests\Fixtures\Resources\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
