<?php

declare(strict_types=1);

namespace Geni\SchemaReader;

enum ColumnType
{
    case Integer;
    case Float;
    case Decimal;
    case String;
    case Text;
    case Boolean;
    case Date;
    case DateTime;
    case Time;
    case Json;
    case Uuid;
    case Ulid;
    case Binary;
    case Unknown;
}
