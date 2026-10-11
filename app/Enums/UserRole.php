<?php

namespace App\Enums;

enum UserRole: string
{
    case Customer = 'customer';
    case Organizer = 'organizer';
    case Admin = 'admin';
}
