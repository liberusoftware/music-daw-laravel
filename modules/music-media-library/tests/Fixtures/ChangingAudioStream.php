<?php

namespace Liberu\Music\MediaLibrary\Tests\Fixtures;

class ChangingAudioStream
{
    public static string $bytes;

    public static string $mode;

    public $context;

    private string $data;

    private int $position = 0;

    private int $rewinds = 0;

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        $this->data = self::$bytes;

        return true;
    }

    public function stream_stat(): array
    {
        return ['size' => strlen(self::$bytes)];
    }

    public function stream_read(int $length): string
    {
        $data = substr($this->data, $this->position, min($length, 1));
        $this->position += strlen($data);

        return $data;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->data);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        if ($offset === 44) {
            if (self::$mode === 'failed-seek') {
                return false;
            }
            if (self::$mode === 'truncated') {
                $this->data = substr($this->data, 0, 44);
            }
            if (self::$mode === 'nonfinite') {
                $this->data = substr_replace($this->data, pack('g', NAN), 44, 4);
            }
        }
        if ($offset === 0 && ++$this->rewinds === 2 && self::$mode === 'changed') {
            $this->data = substr_replace($this->data, pack('v', 12345), 44, 2);
        }
        $this->position = $offset;

        return true;
    }
}
