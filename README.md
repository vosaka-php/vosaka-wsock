# VOsaka WSock

**Vosaka Wsock** is a high-performance, asynchronous WebSocket library for PHP, designed to be lightweight, extensible, and easy to use. It is built on top of [Vosaka Fourotines](https://github.com/venndev/vosaka-fourotines), leveraging cooperative multitasking for efficient handling of concurrent connections.

## Features

- **Asynchronous I/O**: Fully non-blocking server implementation.
- **Room Support**: Built-in support for rooms, allowing easy broadcasting to specific groups of clients.
- **Middleware**: Extensible middleware system (e.g., for rate-limiting, authentication).
- **Handshake Control**: Customizable handshake logic via `onHandshake`.
- **JSON Ready**: Convenient methods for sending and receiving JSON messages.
- **Connection Management**: Robust connection registry and tracking.

## Installation

Install via Composer:

```bash
composer require venndev/vosaka-wsock
```

## Quick Start

Create a simple chat server by extending `AbstractWebSocketHandler`:

```php
use vosaka\wsock\connection\Connection;
use vosaka\wsock\connection\Message;
use vosaka\wsock\server\AbstractWebSocketHandler;
use vosaka\wsock\server\ServerConfig;
use vosaka\wsock\server\WebSocketServer;
use vosaka\wsock\connection\ConnectionRegistry;
use vosaka\foroutines\RunBlocking;
use function vosaka\foroutines\main;

final class ChatHandler extends AbstractWebSocketHandler {
    public function onOpen(Connection $connection): void {
        echo "New connection: {$connection->id}\n";
        $connection->sendText("Welcome to the server!");
    }

    public function onMessage(Connection $connection, Message $message): void {
        if ($message->isText()) {
            echo "Message from {$connection->id}: {$message->getContent()}\n";
            $connection->sendText("Echo: " . $message->getContent());
        }
    }
}

main(function () {
    RunBlocking::new(function () {
        $config = new ServerConfig(host: '0.0.0.0', port: 9000);
        $registry = new ConnectionRegistry();
        $server = new WebSocketServer(
            config: $config,
            handler: new ChatHandler($registry),
            registry: $registry
        );

        $server->start();
        echo "WebSocket server started on ws://localhost:9000\n";
    });
});
```

## Core Components

- **`WebSocketServer`**: The main server class that handles TCP connections and WebSocket handshakes.
- **`AbstractWebSocketHandler`**: Interface/base class for defining your application logic (onOpen, onMessage, onClose).
- **`Connection`**: Represents an active WebSocket client connection.
- **`Room`**: A group of connections for easy broadcasting.
- **`ConnectionRegistry`**: Manages all active connections and their room associations.

## Extensions & Dependencies

- [venndev/vosaka-fourotines](https://github.com/venndev/vosaka-fourotines): The underlying coroutine engine.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
