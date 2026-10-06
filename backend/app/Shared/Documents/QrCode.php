<?php

namespace App\Shared\Documents;

use RuntimeException;

/**
 * QR Code modèle 2, mode octet, correction L, versions 1 à 6.
 * Le dessin inclut la marge blanche exigée pour une lecture imprimée.
 */
class QrCode
{
    /**
     * @var array<int, array{0: int, 1: int, 2: int}>
     */
    private const VERSIONS = [
        1 => [19, 7, 1],
        2 => [34, 10, 1],
        3 => [55, 15, 1],
        4 => [80, 20, 1],
        5 => [108, 26, 1],
        6 => [136, 18, 2],
    ];

    /**
     * @var array<int, list<int>>
     */
    private const ALIGNMENT = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
    ];

    public function png(string $payload, int $module = 8): string
    {
        $modules = $this->matrix($payload);
        $count = count($modules);
        $quiet = 4;
        $size = ($count + ($quiet * 2)) * $module;
        $image = imagecreatetruecolor($size, $size);
        if ($image === false) {
            throw new RuntimeException('Impossible de dessiner le QR code.');
        }
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $size, $size, $white);
        foreach ($modules as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark !== 1) {
                    continue;
                }
                $left = ($x + $quiet) * $module;
                $top = ($y + $quiet) * $module;
                imagefilledrectangle($image, $left, $top, $left + $module - 1, $top + $module - 1, $black);
            }
        }
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    public function dataUri(string $payload): string
    {
        return 'data:image/png;base64,'.base64_encode($this->png($payload));
    }

    /**
     * @return list<list<int>>
     */
    public function matrix(string $payload): array
    {
        $bytes = array_values(unpack('C*', $payload) ?: []);
        $version = $this->version(count($bytes));
        [$dataWords, $eccWords, $blocks] = self::VERSIONS[$version];
        $data = $this->dataCodewords($bytes, $dataWords, $version);
        $perBlock = intdiv($dataWords, $blocks);
        $coded = [];
        for ($block = 0; $block < $blocks; $block++) {
            $slice = array_slice($data, $block * $perBlock, $perBlock);
            $coded[] = array_merge($slice, $this->reedSolomon($slice, $eccWords));
        }
        $stream = $this->interleave($coded, $perBlock, $eccWords);
        $size = 17 + (4 * $version);
        $modules = array_fill(0, $size, array_fill(0, $size, 0));
        $function = array_fill(0, $size, array_fill(0, $size, false));
        $this->drawFunctionPatterns($modules, $function, $version);
        $this->drawData($modules, $function, $this->bits($stream));
        $mask = $this->bestMask($modules, $function);
        $this->applyMask($modules, $function, $mask);
        $this->drawFormat($modules, $mask);

        return $modules;
    }

    private function version(int $length): int
    {
        foreach (self::VERSIONS as $version => [$dataWords]) {
            $countBits = $version <= 9 ? 8 : 16;
            $capacity = $dataWords - (int) ceil((4 + $countBits) / 8) - 1;
            if ($length <= $capacity) {
                return $version;
            }
        }

        throw new RuntimeException('L’adresse de vérification est trop longue pour le QR code.');
    }

    /**
     * @param  list<int>  $bytes
     * @return list<int>
     */
    private function dataCodewords(array $bytes, int $capacity, int $version): array
    {
        $bits = [0, 1, 0, 0];
        $countLength = $version <= 9 ? 8 : 16;
        for ($shift = $countLength - 1; $shift >= 0; $shift--) {
            $bits[] = (count($bytes) >> $shift) & 1;
        }
        foreach ($bytes as $byte) {
            for ($shift = 7; $shift >= 0; $shift--) {
                $bits[] = ($byte >> $shift) & 1;
            }
        }
        $limit = $capacity * 8;
        for ($index = 0; $index < 4 && count($bits) < $limit; $index++) {
            $bits[] = 0;
        }
        while ((count($bits) % 8) !== 0) {
            $bits[] = 0;
        }
        $words = [];
        for ($index = 0; $index < count($bits); $index += 8) {
            $word = 0;
            for ($bit = 0; $bit < 8; $bit++) {
                $word = ($word << 1) | $bits[$index + $bit];
            }
            $words[] = $word;
        }
        $pad = 0xEC;
        while (count($words) < $capacity) {
            $words[] = $pad;
            $pad ^= 0xFD;
        }

        return $words;
    }

    /**
     * @param  list<int>  $data
     * @return list<int>
     */
    private function reedSolomon(array $data, int $ecc): array
    {
        $divisor = array_fill(0, $ecc, 0);
        $divisor[$ecc - 1] = 1;
        $root = 1;
        for ($index = 0; $index < $ecc; $index++) {
            for ($position = 0; $position < $ecc; $position++) {
                $divisor[$position] = $this->multiply($divisor[$position], $root);
                if ($position + 1 < $ecc) {
                    $divisor[$position] ^= $divisor[$position + 1];
                }
            }
            $root = $this->multiply($root, 0x02);
        }
        $result = array_fill(0, $ecc, 0);
        foreach ($data as $word) {
            $factor = $word ^ $result[0];
            array_shift($result);
            $result[] = 0;
            foreach ($divisor as $position => $coefficient) {
                $result[$position] ^= $this->multiply($coefficient, $factor);
            }
        }

        return $result;
    }

    /**
     * @param  list<list<int>>  $blocks
     * @return list<int>
     */
    private function interleave(array $blocks, int $dataLength, int $eccLength): array
    {
        $stream = [];
        for ($index = 0; $index < $dataLength; $index++) {
            foreach ($blocks as $block) {
                $stream[] = $block[$index];
            }
        }
        for ($index = 0; $index < $eccLength; $index++) {
            foreach ($blocks as $block) {
                $stream[] = $block[$dataLength + $index];
            }
        }

        return $stream;
    }

    /**
     * @param  list<int>  $words
     * @return list<int>
     */
    private function bits(array $words): array
    {
        $bits = [];
        foreach ($words as $word) {
            for ($shift = 7; $shift >= 0; $shift--) {
                $bits[] = ($word >> $shift) & 1;
            }
        }

        return $bits;
    }

    /**
     * @param  list<list<int>>  $modules
     * @param  list<list<bool>>  $function
     */
    private function drawFunctionPatterns(array &$modules, array &$function, int $version): void
    {
        $size = count($modules);
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$x, $y]) {
            $this->finder($modules, $function, $x, $y);
        }
        $positions = self::ALIGNMENT[$version];
        if ($positions !== []) {
            $min = $positions[0];
            $max = $positions[count($positions) - 1];
            foreach ($positions as $centerY) {
                foreach ($positions as $centerX) {
                    if ([$centerX, $centerY] === [$min, $min] || [$centerX, $centerY] === [$min, $max] || [$centerX, $centerY] === [$max, $min]) {
                        continue;
                    }
                    $this->alignment($modules, $function, $centerX, $centerY);
                }
            }
        }
        for ($index = 0; $index < $size; $index++) {
            if ($function[$index][6] === false) {
                $this->mark($modules, $function, 6, $index, $index % 2 === 0);
            }
            if ($function[6][$index] === false) {
                $this->mark($modules, $function, $index, 6, $index % 2 === 0);
            }
        }
        $this->mark($modules, $function, 8, 4 * $version + 9, true);
        $this->reserveFormat($function);
    }

    /**
     * @param  list<list<bool>>  $function
     */
    private function reserveFormat(array &$function): void
    {
        $size = count($function);
        for ($bit = 0; $bit < 15; $bit++) {
            if ($bit < 6) {
                $function[$bit][8] = true;
            } elseif ($bit === 6) {
                $function[7][8] = true;
            } elseif ($bit === 7) {
                $function[8][8] = true;
            } elseif ($bit === 8) {
                $function[8][7] = true;
            } else {
                $function[8][14 - $bit] = true;
            }
            if ($bit < 8) {
                $function[8][$size - 1 - $bit] = true;
            } else {
                $function[$size - 15 + $bit][8] = true;
            }
        }
    }

    /**
     * @param  list<list<int>>  $modules
     * @param  list<list<bool>>  $function
     */
    private function finder(array &$modules, array &$function, int $originX, int $originY): void
    {
        for ($y = -1; $y <= 7; $y++) {
            for ($x = -1; $x <= 7; $x++) {
                $dark = $x >= 0 && $x <= 6 && $y >= 0 && $y <= 6 && ($x === 0 || $x === 6 || $y === 0 || $y === 6 || ($x >= 2 && $x <= 4 && $y >= 2 && $y <= 4));
                $this->mark($modules, $function, $originX + $x, $originY + $y, $dark);
            }
        }
    }

    /**
     * @param  list<list<int>>  $modules
     * @param  list<list<bool>>  $function
     */
    private function alignment(array &$modules, array &$function, int $centerX, int $centerY): void
    {
        for ($y = -2; $y <= 2; $y++) {
            for ($x = -2; $x <= 2; $x++) {
                $dark = max(abs($x), abs($y)) !== 1;
                $this->mark($modules, $function, $centerX + $x, $centerY + $y, $dark);
            }
        }
    }

    /**
     * @param  list<list<int>>  $modules
     * @param  list<list<bool>>  $function
     */
    private function mark(array &$modules, array &$function, int $x, int $y, bool $dark): void
    {
        $size = count($modules);
        if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) {
            return;
        }
        $modules[$y][$x] = $dark ? 1 : 0;
        $function[$y][$x] = true;
    }

    /**
     * @param  list<list<int>>  $modules
     * @param  list<list<bool>>  $function
     * @param  list<int>  $bits
     */
    private function drawData(array &$modules, array $function, array $bits): void
    {
        $size = count($modules);
        $index = 0;
        $upward = true;
        for ($column = $size - 1; $column > 0; $column -= 2) {
            if ($column === 6) {
                $column--;
            }
            for ($step = 0; $step < $size; $step++) {
                $row = $upward ? $size - 1 - $step : $step;
                foreach ([$column, $column - 1] as $x) {
                    if ($function[$row][$x]) {
                        continue;
                    }
                    $modules[$row][$x] = $bits[$index] ?? 0;
                    $index++;
                }
            }
            $upward = ! $upward;
        }
    }

    /**
     * @param  list<list<int>>  $modules
     * @param  list<list<bool>>  $function
     */
    private function bestMask(array $modules, array $function): int
    {
        $best = 0;
        $score = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $copy = $modules;
            $this->applyMask($copy, $function, $mask);
            $this->drawFormat($copy, $mask);
            $penalty = $this->penalty($copy);
            if ($penalty < $score) {
                $score = $penalty;
                $best = $mask;
            }
        }

        return $best;
    }

    /**
     * @param  list<list<int>>  $modules
     * @param  list<list<bool>>  $function
     */
    private function applyMask(array &$modules, array $function, int $mask): void
    {
        $size = count($modules);
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($function[$y][$x] || ! $this->masked($mask, $y, $x)) {
                    continue;
                }
                $modules[$y][$x] ^= 1;
            }
        }
    }

    private function masked(int $mask, int $y, int $x): bool
    {
        return match ($mask) {
            0 => ($y + $x) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($y + $x) % 3 === 0,
            4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
            5 => (($y * $x) % 2) + (($y * $x) % 3) === 0,
            6 => ((($y * $x) % 2) + (($y * $x) % 3)) % 2 === 0,
            default => ((($y + $x) % 2) + (($y * $x) % 3)) % 2 === 0,
        };
    }

    /**
     * @param  list<list<int>>  $modules
     */
    private function drawFormat(array &$modules, int $mask): void
    {
        $value = (1 << 3) | $mask;
        $remainder = $value << 10;
        for ($bit = 14; $bit >= 10; $bit--) {
            if ((($remainder >> $bit) & 1) === 1) {
                $remainder ^= 0x537 << ($bit - 10);
            }
        }
        $format = (($value << 10) | ($remainder & 0x3FF)) ^ 0x5412;
        $size = count($modules);
        for ($bit = 0; $bit < 15; $bit++) {
            $dark = (($format >> $bit) & 1) === 1;
            if ($bit < 6) {
                $modules[$bit][8] = $dark ? 1 : 0;
            } elseif ($bit === 6) {
                $modules[7][8] = $dark ? 1 : 0;
            } elseif ($bit === 7) {
                $modules[8][8] = $dark ? 1 : 0;
            } elseif ($bit === 8) {
                $modules[8][7] = $dark ? 1 : 0;
            } else {
                $modules[8][14 - $bit] = $dark ? 1 : 0;
            }
            if ($bit < 8) {
                $modules[8][$size - 1 - $bit] = $dark ? 1 : 0;
            } else {
                $modules[$size - 15 + $bit][8] = $dark ? 1 : 0;
            }
        }
    }

    /**
     * @param  list<list<int>>  $modules
     */
    private function penalty(array $modules): int
    {
        $size = count($modules);
        $score = 0;
        foreach ([$modules, $this->transpose($modules)] as $grid) {
            foreach ($grid as $row) {
                $run = 1;
                for ($index = 1; $index < $size; $index++) {
                    if ($row[$index] === $row[$index - 1]) {
                        $run++;
                        if ($run === 5) {
                            $score += 3;
                        } elseif ($run > 5) {
                            $score++;
                        }
                    } else {
                        $run = 1;
                    }
                }
            }
        }
        $dark = 0;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $dark += $modules[$y][$x];
                if ($x < $size - 1 && $y < $size - 1) {
                    $cell = $modules[$y][$x];
                    if ($cell === $modules[$y][$x + 1] && $cell === $modules[$y + 1][$x] && $cell === $modules[$y + 1][$x + 1]) {
                        $score += 3;
                    }
                }
            }
        }
        $ratio = abs((intdiv($dark * 100, $size * $size)) - 50);
        $score += intdiv($ratio, 5) * 10;

        return $score;
    }

    /**
     * @param  list<list<int>>  $modules
     * @return list<list<int>>
     */
    private function transpose(array $modules): array
    {
        $size = count($modules);
        $grid = [];
        for ($x = 0; $x < $size; $x++) {
            $row = [];
            for ($y = 0; $y < $size; $y++) {
                $row[] = $modules[$y][$x];
            }
            $grid[] = $row;
        }

        return $grid;
    }

    private function multiply(int $left, int $right): int
    {
        $result = 0;
        for ($bit = 0; $bit < 8; $bit++) {
            if ((($right >> $bit) & 1) === 1) {
                $result ^= $left << $bit;
            }
        }
        for ($bit = 14; $bit >= 8; $bit--) {
            if ((($result >> $bit) & 1) === 1) {
                $result ^= 0x11D << ($bit - 8);
            }
        }

        return $result;
    }
}
