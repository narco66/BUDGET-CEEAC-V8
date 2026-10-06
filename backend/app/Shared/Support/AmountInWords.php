<?php

namespace App\Shared\Support;

class AmountInWords
{
    public function fcfa(int $amount): string
    {
        if ($amount < 0) {
            return 'moins '.$this->fcfa(abs($amount));
        }

        $words = $amount === 0 ? 'zéro' : $this->french($amount);

        return $words.' '.($amount > 1 ? 'francs CFA' : 'franc CFA');
    }

    private function french(int $amount): string
    {
        if ($amount < 20) {
            return $this->units($amount);
        }
        if ($amount < 100) {
            return $this->tens($amount);
        }
        if ($amount < 1000) {
            $hundreds = intdiv($amount, 100);
            $rest = $amount % 100;
            $word = $hundreds > 1 ? $this->units($hundreds).' cent' : 'cent';
            if ($rest === 0 && $hundreds > 1) {
                $word .= 's';
            }

            return $rest === 0 ? $word : $word.' '.$this->french($rest);
        }

        $scales = [1000000000 => 'milliard', 1000000 => 'million', 1000 => 'mille'];
        foreach ($scales as $scale => $label) {
            if ($amount < $scale) {
                continue;
            }
            $count = intdiv($amount, $scale);
            $rest = $amount % $scale;
            $word = $label === 'mille' && $count === 1
                ? 'mille'
                : trim($this->french($count).' '.$label);
            if ($count > 1 && $label !== 'mille') {
                $word .= 's';
            }

            return $rest === 0 ? $word : $word.' '.$this->french($rest);
        }

        return $this->tens($amount);
    }

    private function tens(int $amount): string
    {
        $tens = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante', 8 => 'quatre-vingt'];
        $ten = intdiv($amount, 10);
        $unit = $amount % 10;
        if ($ten === 7 || $ten === 9) {
            $base = $ten === 7 ? 'soixante' : 'quatre-vingt';
            $remainder = 10 + $unit;

            return $base.($remainder === 11 ? ' et ' : '-').$this->units($remainder);
        }
        $word = $tens[$ten];
        if ($unit === 0) {
            return $ten === 8 ? $word.'s' : $word;
        }
        if ($unit === 1 && $ten < 8) {
            return $word.' et un';
        }

        return $word.'-'.$this->units($unit);
    }

    private function units(int $amount): string
    {
        return [
            0 => 'zéro', 1 => 'un', 2 => 'deux', 3 => 'trois', 4 => 'quatre', 5 => 'cinq',
            6 => 'six', 7 => 'sept', 8 => 'huit', 9 => 'neuf', 10 => 'dix', 11 => 'onze',
            12 => 'douze', 13 => 'treize', 14 => 'quatorze', 15 => 'quinze', 16 => 'seize',
            17 => 'dix-sept', 18 => 'dix-huit', 19 => 'dix-neuf',
        ][$amount];
    }
}
