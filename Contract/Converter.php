<?php
declare(strict_types=1);

namespace MediaHandler\Contract;

interface Converter
{
    /**
     * @return string Path of the newly converted file
     */
    public function convert(): string;
}