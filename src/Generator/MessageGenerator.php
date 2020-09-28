<?php

declare(strict_types=1);

namespace Longyan\Kafka\Generator;

class MessageGenerator extends AbstractGenerator
{
    /**
     * @var string
     */
    protected $jsonFileName;

    /**
     * @var string
     */
    protected $dirName;

    /**
     * @var bool
     */
    protected $isHeader = false;

    /**
     * @var int[]
     */
    protected $flexibleVersions;

    /**
     * @var string[]
     */
    protected $generatedTypes = [];

    /**
     * @var int
     */
    protected $apiKey;

    public function __construct(string $jsonFileName)
    {
        $this->messageGenerator = $this;
        $this->jsonFileName = $jsonFileName;
        $this->data = $data = json5_decode(file_get_contents($jsonFileName));
        $this->apiName = $this->parseApiName($data);
        $this->dirName = \dirname(__DIR__) . '/Protocol/' . $this->apiName;
        $this->validVersions = $this->parseVersionsToArray($data->validVersions);
        $this->maxSupportVersion = $maxSupportVersion = max(0, ...$this->validVersions);
        $this->flexibleVersions = isset($data->flexibleVersions) ? $this->parseVersionsToArray($data->flexibleVersions, $maxSupportVersion) : [];
        $this->apiKey = $this->data->apiKey ?? -1;
    }

    public function getJsonFileName(): string
    {
        return $this->jsonFileName;
    }

    public function getDirName(): string
    {
        return $this->dirName;
    }

    public function getIsHeader(): bool
    {
        return $this->isHeader;
    }

    public function getSaveFileName(): string
    {
        return $this->dirName . '/' . $this->data->name . '.php';
    }

    public function getFlexibleVersions(): array
    {
        return $this->flexibleVersions;
    }

    public function getApiKey(): int
    {
        return $this->apiKey;
    }

    public function generate(): void
    {
        $this->generateCommonStructs();
        [$classProperties, $constructMethod, $methods] = $this->generateCode();

        $className = $this->data->name;
        $flexibleVersionsStr = json_encode($this->flexibleVersions);
        switch ($this->data->type) {
            case 'request':
                $extendsClassName = 'AbstractRequest';
                $constructMethod .= <<<CODE

public function getRequestApiKey(): ?int
{
    return {$this->apiKey};
}

public function getMaxSupportedVersion(): int
{
    return {$this->maxSupportVersion};
}

public function getFlexibleVersions(): array
{
    return {$flexibleVersionsStr};
}

CODE;
                break;
            case 'response':
                $extendsClassName = 'AbstractResponse';
                $constructMethod .= <<<CODE

public function getRequestApiKey(): ?int
{
    return {$this->apiKey};
}

public function getFlexibleVersions(): array
{
    return {$flexibleVersionsStr};
}

CODE;
                break;
            case 'header':
                if ('RequestHeader' === $this->data->name) {
                    $extendsClassName = 'AbstractRequestHeader';
                } elseif ('ResponseHeader' === $this->data->name) {
                    $extendsClassName = 'AbstractResponseHeader';
                } else {
                    throw new \InvalidArgumentException(sprintf('Invalid name %s', $this->data->name));
                }
                $constructMethod .= <<<CODE

public function getFlexibleVersions(): array
{
    return {$flexibleVersionsStr};
}

CODE;
                break;
        }

        $classContent = <<<CODE
<?php

declare(strict_types=1);

namespace Longyan\Kafka\Protocol\\{$this->apiName};

use Longyan\Kafka\Protocol\\{$extendsClassName};
use Longyan\Kafka\Protocol\ProtocolField;

class {$className} extends {$extendsClassName}
{
    {$classProperties}

    {$constructMethod}

    {$methods}
}
CODE;
        $this->save($classContent);
    }

    public function generateCommonStructs()
    {
        if (!isset($this->data->commonStructs)) {
            return;
        }
        foreach ($this->data->commonStructs as $struct) {
            $this->generateStruct($struct->name, $struct);
        }
    }

    public function hasGenerated(string $type): bool
    {
        return isset($this->generatedTypes[$type]);
    }

    public function generateStruct(string $type, \stdClass $field): void
    {
        $generator = new StructGenerator($this, $field);
        $generator->generate();
        $this->generatedTypes[$type] = true;
    }

    protected function parseApiName(\stdClass $data): string
    {
        $matches = null;
        if (preg_match('/^(.+)(Request|Response|(?<header>Header))$/', $data->name, $matches) <= 0) {
            throw new \RuntimeException(sprintf('Invalid name %s', $data->name));
        }

        $header = $matches['header'] ?? '';
        if ('' !== $header) {
            $this->isHeader = true;
        }

        return $matches[1] . $header;
    }
}