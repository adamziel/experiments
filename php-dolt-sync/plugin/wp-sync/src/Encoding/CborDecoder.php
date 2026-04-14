<?php
declare(strict_types=1);

namespace WpSync\Encoding;

final class CborDecoder
{
    private string $buf;
    private int $pos;
    private int $len;

    public function __construct(string $bytes)
    {
        $this->buf = $bytes;
        $this->pos = 0;
        $this->len = strlen($bytes);
    }

    /** Decode a whole CBOR item. Throws if bytes remain. */
    public static function decode(string $bytes): mixed
    {
        $d = new self($bytes);
        $v = $d->readItem();
        if ($d->pos !== $d->len) {
            throw new \RuntimeException('CBOR: trailing bytes after top-level item');
        }
        return $v;
    }

    public function readItem(): mixed
    {
        if ($this->pos >= $this->len) {
            throw new \RuntimeException('CBOR: unexpected EOF');
        }
        $ib = ord($this->buf[$this->pos++]);
        $mt = $ib >> 5;
        $ai = $ib & 0x1f;
        $arg = $this->readArg($ai, $mt);

        switch ($mt) {
            case CborEncoder::MT_UINT:
                return $arg;
            case CborEncoder::MT_NINT:
                return -1 - $arg;
            case CborEncoder::MT_BSTR:
            case CborEncoder::MT_TSTR:
                $s = substr($this->buf, $this->pos, $arg);
                if (strlen($s) !== $arg) {
                    throw new \RuntimeException('CBOR: short read in string');
                }
                $this->pos += $arg;
                // text strings marked as distinct using internal wrapper? We return PHP strings
                // for both; callers distinguish by context. Our encoder only emits text for keys.
                return $s;
            case CborEncoder::MT_ARRAY:
                $a = [];
                for ($i = 0; $i < $arg; $i++) {
                    $a[] = $this->readItem();
                }
                return $a;
            case CborEncoder::MT_MAP:
                $m = [];
                for ($i = 0; $i < $arg; $i++) {
                    $k = $this->readItem();
                    $v = $this->readItem();
                    // Keys can be ints or strings; PHP handles both as array keys.
                    $m[$k] = $v;
                }
                return $m;
            case CborEncoder::MT_SIMPLE:
                if ($ai === 20) return false;
                if ($ai === 21) return true;
                if ($ai === 22) return null;
                if ($ai === 23) return null; // undefined -> null
                if ($ai === 25) {
                    // half float
                    return $this->decodeHalf($arg);
                }
                if ($ai === 26) {
                    // single float
                    $b = pack('N', $arg);
                    $u = unpack('G', $b); // big-endian float
                    return (float) $u[1];
                }
                if ($ai === 27) {
                    $b = pack('J', $arg);
                    $u = unpack('E', $b);
                    return (float) $u[1];
                }
                throw new \RuntimeException('CBOR: unsupported simple value ai=' . $ai);
            case CborEncoder::MT_TAG:
                // Skip tag; return tagged value.
                return $this->readItem();
            default:
                throw new \RuntimeException('CBOR: unknown major type ' . $mt);
        }
    }

    private function readArg(int $ai, int $mt): int
    {
        if ($ai < 24) {
            return $ai;
        }
        if ($ai === 24) {
            return ord($this->readBytes(1));
        }
        if ($ai === 25) {
            $b = $this->readBytes(2);
            if ($mt === CborEncoder::MT_SIMPLE) {
                // half-float: pass the raw 16-bit integer through readArg
                return (ord($b[0]) << 8) | ord($b[1]);
            }
            return (ord($b[0]) << 8) | ord($b[1]);
        }
        if ($ai === 26) {
            $b = $this->readBytes(4);
            $u = unpack('N', $b);
            if ($mt === CborEncoder::MT_SIMPLE) {
                return $u[1];
            }
            return $u[1];
        }
        if ($ai === 27) {
            $b = $this->readBytes(8);
            $u = unpack('J', $b);
            return $u[1];
        }
        throw new \RuntimeException('CBOR: unsupported additional info ai=' . $ai);
    }

    private function readBytes(int $n): string
    {
        $s = substr($this->buf, $this->pos, $n);
        if (strlen($s) !== $n) {
            throw new \RuntimeException('CBOR: short read');
        }
        $this->pos += $n;
        return $s;
    }

    private function decodeHalf(int $u): float
    {
        $exp = ($u >> 10) & 0x1f;
        $mant = $u & 0x3ff;
        $sign = ($u >> 15) & 1;
        if ($exp === 0) {
            $val = $mant * 2 ** -24;
        } elseif ($exp !== 31) {
            $val = ($mant + 1024) * 2 ** ($exp - 25);
        } else {
            $val = $mant === 0 ? INF : NAN;
        }
        return $sign ? -$val : $val;
    }
}
