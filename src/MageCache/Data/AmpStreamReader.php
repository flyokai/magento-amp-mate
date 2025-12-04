<?php

namespace Flyokai\MagentoAmpMate\MageCache\Data;

use Amp\ByteStream\ReadableStream;
use Amp\Parser\Parser;

class AmpStreamReader
{
    protected Parser $parser;

    public function __construct(
        protected ReadableStream $stream
    ) {
        $this->parser = new Parser($this->parse());
    }

    protected function parse()
    {
        $this->meta = rtrim(yield "\n");
        while (true) {
            $this->data .= yield \Amp\File\File::DEFAULT_READ_LENGTH;
        }
    }

    public $meta;
    public function getMeta(): string
    {
        $this->read(true);
        return $this->meta??'';
    }

    public function getData(): string
    {
        $this->read(false);
        return $this->data??'';
    }

    protected $onlyMeta = false;
    public $data;
    protected function read(bool $onlyMeta = true): void
    {
        $this->onlyMeta = $onlyMeta;
        while (null !== $chunk = $this->stream->read()) {
            $this->parser->push($chunk);
            if ($this->onlyMeta && isset($this->meta)) {
                return;
            }
        }
        $this->data .= $this->parser->cancel();
        $this->data = rtrim($this->data);
    }
}
