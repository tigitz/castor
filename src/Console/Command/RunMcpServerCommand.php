<?php

namespace Castor\Console\Command;

use Castor\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;

/** @internal */
class RunMcpServerCommand extends Command
{
    public function __construct(
        private readonly Application $application,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('castor:run-mcp-server')
            ->setAliases(['run-mcp-server'])
            ->setDescription('Run an MCP server that exposes Castor tasks')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Port to run the server on', $this->port)
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host to bind the server to', $this->host)
            ->addOption('log-file', 'l', InputOption::VALUE_REQUIRED, 'Log file path')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        if ($logFile = $input->getOption('log-file')) {
            $this->logFile = $logFile;
        }
        
        $this->log("Starting MCP server");
        
        $buffer = '';

        while (true) {
            $line = fgets(STDIN);
            if (false === $line) {
                usleep(1000);
                continue;
            }
            
            $buffer .= $line;
            if (str_contains($buffer, "\n")) {
                $lines = explode("\n", $buffer);
                $buffer = array_pop($lines);
                foreach ($lines as $line) {
                    if (trim($line) !== '') {
                        $this->processLine($output, $line);
                    }
                }
            }
        }

        return Command::SUCCESS;
    }
    
    private function processLine(OutputInterface $output, string $line): void
    {
        $this->log("Received: " . $line);
        
        try {
            $payload = json_decode($line, true, JSON_THROW_ON_ERROR);

            $method = $payload['method'] ?? null;
            $id = $payload['id'] ?? null;

            $response = match ($method) {
                'initialize' => $this->sendInitialize(),
                'tools/list' => $this->sendToolsList(),
                'tools/call' => $this->callTool($payload['params'] ?? []),
                'notifications/initialized' => null,
                default => $this->sendProtocolError(sprintf('Method "%s" not found', $method)),
            };
        } catch (\Throwable $e) {
            $response = $this->sendApplicationError($e);
        }

        if (!$response) {
            return;
        }

        $response['id'] = $id ?? 0;
        $response['jsonrpc'] = '2.0';

        $responseJson = json_encode($response);
        $this->log("Sending: " . $responseJson);
        $output->writeln($responseJson);
    }
    
    private function sendInitialize(): array
    {
        return [
            'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [
                    'tools' => [
                        'listChanged' => true
                    ]
                ],
                'serverInfo' => [
                    'name' => 'CastorMcpServer',
                    'version' => Application::VERSION,
                ]
            ]
        ];
    }
    
    private function sendToolsList(): array
    {
        $tools = [];
        
        foreach ($this->application->all() as $command) {
            // Skip hidden commands and the MCP server command itself
            if ($command->isHidden() || $command->getName() === $this->getName()) {
                continue;
            }

            // Get command arguments and options for schema
            $argumentsSchema = [];
            foreach ($command->getDefinition()->getArguments() as $argument) {
                $argumentsSchema[$argument->getName()] = [
                    'type' => $argument->isArray() ? 'array' : 'string',
                    'description' => $argument->getDescription() ?: 'No description available',
                    'required' => $argument->isRequired(),
                ];
                if ($argument->getDefault() !== null) {
                    $argumentsSchema[$argument->getName()]['default'] = $argument->getDefault();
                }
            }
            
            $optionsSchema = [];
            foreach ($command->getDefinition()->getOptions() as $option) {
                $optionsSchema[$option->getName()] = [
                    'type' => $option->acceptValue() 
                        ? ($option->isArray() ? 'array' : 'string') 
                        : 'boolean',
                    'description' => $option->getDescription() ?: 'No description available',
                ];
                if ($option->isValueRequired()) {
                    $optionsSchema[$option->getName()]['required'] = true;
                }
                if ($option->getDefault() !== null) {
                    $optionsSchema[$option->getName()]['default'] = $option->getDefault();
                }
                if ($option->getShortcut()) {
                    $optionsSchema[$option->getName()]['shortcut'] = $option->getShortcut();
                }
            }
            
            // Build required fields array
            $required = [];
            foreach ($argumentsSchema as $name => $schema) {
                if (isset($schema['required']) && $schema['required']) {
                    $required[] = $name;
                }
            }
            
            $tools[] = [
                'name' => $command->getName(),
                'description' => $command->getDescription() ?: 'No description available',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'arguments' => [
                            'type' => 'object',
                            'properties' => $argumentsSchema,
                            'description' => 'Command arguments',
                        ],
                        'options' => [
                            'type' => 'object',
                            'properties' => $optionsSchema,
                            'description' => 'Command options',
                        ],
                    ],
                    'required' => ['arguments'],
                    '$schema' => 'http://json-schema.org/draft-07/schema#',
                ]
            ];
        }
        
        //@TODO Check if tools list has changed
        // $this->sendToolsListChangedNotification();

        return [
            'result' => [
                'tools' => $tools
            ]
        ];
    }
    
    private function sendToolsListChangedNotification(): void
    {
        $notification = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed'
        ];
        
        $this->log("Sending notification: " . json_encode($notification));
        echo json_encode($notification) . "\n";
    }
    
    private function callTool(array $params): array
    {
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];
        
        try {
            // Validate tool exists
            if (!$name) {
                return $this->sendProtocolError('Tool name is required', -32602);
            }
            
            try {
                $command = $this->application->find($name);
            } catch (\Throwable $e) {
                return $this->sendProtocolError("Unknown tool: $name", -32602);
            }
            
            $inputArgs = ['command' => $name];
            if (isset($arguments['arguments'])) {
                foreach ($arguments['arguments'] as $key => $value) {
                    $inputArgs[$key] = $value;
                }
            }
            
            if (isset($arguments['options'])) {
                foreach ($arguments['options'] as $key => $value) {
                    if (is_bool($value) && $value === true) {
                        $inputArgs['--' . $key] = null;
                    } else {
                        $inputArgs['--' . $key] = $value;
                    }
                }
            }
            
            $input = new ArrayInput($inputArgs);
            $output = new BufferedOutput();
            
            $exitCode = $command->run($input, $output);
            $outputText = $output->fetch();
            
            // Consider non-zero exit codes as errors
            $isError = $exitCode !== 0;
            
            return [
                'result' => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $outputText,
                        ],
                    ],
                    'isError' => $isError,
                ],
            ];
        } catch (\Throwable $e) {
            // For tool execution errors, return a properly formatted tool result with isError=true
            return [
                'result' => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Error executing tool: ' . $e->getMessage(),
                        ],
                    ],
                    'isError' => true,
                ],
            ];
        }
    }
    
    private function sendProtocolError(string $message, int $code = -32601): array
    {
        $this->log("Protocol Error: $code - $message");
        
        return [
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
    
    private function sendApplicationError(\Throwable $e): array
    {
        $this->log("Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        
        return [
            'error' => [
                'code' => -32000,
                'message' => $e->getMessage(),
                'data' => [
                    'trace' => $e->getTraceAsString(),
                ],
            ],
        ];
    }
    
    private function log(string $message): void
    {
        if ($this->logger) {
            $this->logger->debug('[MCP] ' . $message);
        }
        
        file_put_contents($this->logFile, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }
}
