<?php

namespace Cesa\Rekrutmen\Enums;

enum WhatsAppAccountStatus: string
{
    case Unknown = 'unknown';
    case Qr = 'qr';
    case Pairing = 'pairing';
    case Connecting = 'connecting';
    case Connected = 'connected';
    case Disconnected = 'disconnected';
}
