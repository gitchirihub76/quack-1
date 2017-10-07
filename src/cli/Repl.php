<?php
/**
 * Quack Compiler and toolkit
 * Copyright (C) 2016 Marcelo Camargo <marcelocamargo@linuxmail.org> and
 * CONTRIBUTORS.
 *
 * This file is part of Quack.
 *
 * Quack is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Quack is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Quack.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace QuackCompiler\Cli;

require 'Component.php';

class Repl extends Component
{
    private $console;

    public function __construct(Console $console)
    {
        $this->console = $console;
        parent::__construct([
            'line'          => [],
            'column'        => 0,
            'history'       => [],
            'history_index' => 0
        ]);
    }

    private function resetState()
    {
        $this->setState([
            'line'          => [],
            'column'        => 0,
            'history_index' => 0
        ]);
    }

    private function getEvent($char_code)
    {
        switch ($char_code) {
            case 0x7F:
                return [$this, 'handleBackspace'];
            case 0xC:
                return [$this, 'handleClearScreen'];
            case 0x1B:
                return [$this, 'handleEscape'];
            default:
                return null;
        }
    }

    private function handleEscape()
    {
        $next = ord($this->console->getChar());
        switch ($next) {
            case 0x4F:
                return $this->handleHomeAndEnd();
            case 0x5B:
                return $this->handleGenericEvent();
        }
    }

    private function handleGenericEvent()
    {
        $next = ord($this->console->getChar());
        list ($line, $column) = $this->state('line', 'column');
        $arrow_events = [
            0x43 => min(sizeof($line), $column + 1),
            0x44 => max(0, $column - 1)
        ];

        if (isset($arrow_events[$next])) {
            $this->setState(['column' => $arrow_events[$next]]);
        }

        if (0x41 === $next || 0x42 === $next) {
            list ($history, $index) = $this->state('history', 'history_index');
            $history_size = sizeof($history);

            // Handle key up and down
            $navigator = 0x41 === $next ? 1 : -1;
            $line = @$history[$history_size - ($index + $navigator)];
            if (null !== $line) {
                $this->setState([
                    'line'          => str_split($line),
                    'history_index' => $index + $navigator,
                    'column'        => strlen($line)
                ]);
            } elseif (0x42 === $next && $index <= 1) {
                $this->resetState();
            }
        }

        // Delete
        if (0x33 === $next) {
            // Discard garbage escape
            $this->console->getChar();
            if ($column === sizeof($line)) {
                return;
            }

            array_splice($line, $column, 1);
            $this->setState([
                'line'   => $line,
                'column' => $column
            ]);
        }
    }

    private function handleBackspace()
    {
        list ($line, $column) = $this->state('line', 'column');

        if (0 === $column) {
            return;
        }

        array_splice($line, $column - 1, 1);
        $this->setState([
            'line'   => $line,
            'column' => $column - 1
        ]);
    }

    private function handleClearScreen()
    {
        $this->console->clear();
        $this->console->moveCursorToHome();
        $this->setState([]);
    }

    private function handleHomeAndEnd()
    {
        $next = ord($this->console->getChar());

        // End
        if (0x46 === $next) {
            $this->setState(['column' => sizeof($this->state('line'))]);
        }

        // Home
        if (0x48 === $next) {
            $this->setState(['column' => 0]);
        }
    }

    private function handleEnter()
    {
        $line = trim(implode('', $this->state('line')));
        // Push line to the history
        if ($line !== '') {
            $this->setState([
                'history' => array_merge($this->state('history'), [$line])
            ]);
        }

        // Go to the start of line and set the command as done
        $this->console->resetCursor();
        $this->renderPrompt(Console::FG_CYAN);
        $this->console->writeln('');
    }

    private function handleKeyPress($input)
    {
        if (ctype_cntrl($input)) {
            return;
        }

        list ($line, $column) = $this->state('line', 'column');
        $next_buffer = [$input];
        // Insert the new char in the column in the line buffer
        array_splice($line, $column, 0, $next_buffer);

        $this->setState([
            'line'   => $line,
            'column' => $this->state('column') + strlen($input)
        ]);
    }

    public function handleQuit()
    {
        $this->console->setColor(Console::FG_BLUE);
        $this->console->writeln(' > So long, and thanks for all the fish!');
        $this->console->resetColor();
        exit;
    }

    private function intercept($command)
    {
        switch ($command) {
            case ':clear':
                return $this->handleClearScreen();
            case ':quit':
                return $this->handleQuit();
        }
    }

    public function welcome()
    {
        $prelude = [
            'Quack - Copyright (C) 2017 Marcelo Camargo',
            'This program comes with ABSOLUTELY NO WARRANTY.',
            'This is free software, and you are welcome to redistribute it',
            'under certain conditions.',
            'Use quack --help for more information',
            'Type ^C or :quit to leave'
        ];

        $this->console->setTitle('Quack interactive mode');
        foreach ($prelude as $line) {
            $this->console->writeln($line);
        }
    }

    public function handleRead()
    {
        $this->console->sttySaveCheckpoint();
        $this->console->sttyEnableCharEvents();

        do {
            $char = $this->console->getChar();
            $event = $this->getEvent(ord($char));
            if (null !== $event) {
                call_user_func($event);
                continue;
            }

            $this->handleKeyPress($char);
        } while (ord($char) !== 10);

        $this->handleEnter();
        $this->console->sttyRestoreCheckpoint();
    }

    private function renderPrompt($color = Console::FG_YELLOW)
    {
        $this->console->setColor($color);
        $this->console->write('Quack> ');
        $this->console->resetColor();
    }

    private function renderLeftScroll()
    {
        $this->console->setColor(Console::BG_WHITE);
        $this->console->setColor(Console::FG_BLACK);
        $this->console->write(' < ');
        $this->console->setColor(Console::FG_CYAN);
        $this->console->write(' ... ');
        $this->console->resetColor();
    }

    public function render()
    {
        $line = implode('', $this->state('line'));
        $column = $this->state('column');

        $this->console->clearLine();
        $this->console->resetCursor();

        $workspace = $this->console->getWidth() - 7;
        $this->renderPrompt();
        $show_left_scroll = $column >= $workspace;
        $text_size = $workspace;

        if ($show_left_scroll) {
            $this->renderLeftScroll();
            $text_size -= 9;
        }

        $from = $column - $text_size;
        $cursor = 7 + ($workspace - ($text_size - $column));
        $limit = $from <= 0 ? 1 : 0;
        $this->console->write(substr($line, max(0, $from), $text_size - $limit));
        $this->console->resetCursor();
        $this->console->forwardCursor($cursor);
    }

    public function start()
    {
        $this->render();
        while (true) {
            $this->handleRead();
            $line = trim(implode('', $this->state('line')));

            if (':' === substr($line, 0, 1)) {
                $this->intercept($line);
            }

            $this->resetState();
        }
    }
}

require 'Console.php';

$repl = new Repl(new Console(STDIN, STDOUT, STDERR));
$repl->welcome();
$repl->start();
