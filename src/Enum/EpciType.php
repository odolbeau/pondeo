<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The four legal categories of EPCI (Établissement Public de Coopération
 * Intercommunale). Spelled out in full rather than as acronyms (CA/CC/CU).
 */
enum EpciType: string
{
    case CommunauteDeCommunes = 'communaute_de_communes';
    case CommunauteDAgglomeration = 'communaute_d_agglomeration';
    case CommunauteUrbaine = 'communaute_urbaine';
    case Metropole = 'metropole';
}
