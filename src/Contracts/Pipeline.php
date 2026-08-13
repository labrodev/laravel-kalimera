<?php

declare(strict_types=1);

namespace Kalimera\Contracts;

interface Pipeline
{
    public function label(): string;

    public function execute(): void;
}
