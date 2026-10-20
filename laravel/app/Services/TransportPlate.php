<?php

namespace App\Services;

final class TransportPlate
{
    public const MAX_ALPHANUMERIC_LENGTH = 8;

    public const PATTERN = '(?=(?:(?: |-)*[A-Z0-9]){6,8}$)(?=.*[0-9])[A-Z0-9]+(?:(?: |-)[A-Z0-9]+)*';

    public const REGEX = '/^'.self::PATTERN.'$/D';

    public const MESSAGE = 'La placa debe tener de 6 a 8 letras o numeros, incluyendo al menos un numero. Se permiten espacios o guiones entre grupos y el prefijo es opcional.';
}
