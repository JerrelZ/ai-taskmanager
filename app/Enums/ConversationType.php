<?php

namespace App\Enums;

enum ConversationType: string
{
    case Dm = 'dm';
    case Group = 'group';
    case Project = 'project';
}
