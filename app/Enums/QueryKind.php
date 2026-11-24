<?php

namespace App\Enums;

enum QueryKind: string
{
    case Printing = 'printing';
    case Set = 'set';
    case Text = 'text';
}
