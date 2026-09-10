<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Ui;

/**
 * What an action tells the panel to do next.
 *
 * A page handler returns a {@see Page}. An action handler returns one of
 * these: show a notification, re-render the page, send the viewer somewhere,
 * or replace what is on screen.
 */
final class UiResponse implements \JsonSerializable
{
    private ?string $message = null;

    private string $level = 'success';

    private bool $refresh = false;

    private ?string $redirect = null;

    private ?Page $page = null;

    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    private function __construct()
    {
    }

    /**
     * A toast, and re-render the page so it reflects whatever just changed.
     */
    public static function notify(string $message, string $level = 'success'): self
    {
        $response = new self();
        $response->message = $message;
        $response->level = $level;
        $response->refresh = true;

        return $response;
    }

    /**
     * Answer a front end with data rather than telling the panel to draw
     * something.
     *
     * Only useful to a page the plugin draws itself: a declarative page has
     * no way to receive this, because its whole point is that the plugin
     * describes what to draw and the panel decides how. A page served as its
     * own front end needs the other half, which is somewhere to get its state
     * from, and this is it.
     *
     * @param array<string, mixed> $data
     */
    public static function data(array $data, ?string $message = null): self
    {
        $response = new self();
        $response->data = $data;
        $response->message = $message;

        return $response;
    }

    /**
     * Re-render without saying anything.
     */
    public static function refresh(): self
    {
        $response = new self();
        $response->refresh = true;

        return $response;
    }

    /**
     * Send the viewer to a URL. An external one opens in a new tab, so the
     * panel is never navigated away from underneath someone.
     */
    public static function redirect(string $url): self
    {
        $response = new self();
        $response->redirect = $url;

        return $response;
    }

    /**
     * Swap in a different page, for a wizard or a drill-down.
     */
    public static function replace(Page $page): self
    {
        $response = new self();
        $response->page = $page;

        return $response;
    }

    /**
     * Reject a form submission and show the reasons against the fields.
     *
     * Keys are field names, so the message lands under the input that caused
     * it rather than in a toast the viewer has to map back themselves.
     *
     * @param array<string, string|list<string>> $errors
     */
    public static function invalid(array $errors, ?string $message = null): self
    {
        $response = new self();
        $response->level = 'danger';
        $response->message = $message ?? 'Please correct the errors below.';

        foreach ($errors as $field => $error) {
            $response->errors[$field] = is_array($error) ? array_values($error) : [$error];
        }

        return $response;
    }

    public static function error(string $message): self
    {
        return self::notify($message, 'danger');
    }

    public static function warning(string $message): self
    {
        return self::notify($message, 'warning');
    }

    public function withoutRefresh(): self
    {
        $this->refresh = false;

        return $this;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'message' => $this->message,
            'level' => $this->message !== null ? $this->level : null,
            'refresh' => $this->refresh ?: null,
            'redirect' => $this->redirect,
            'page' => $this->page,
            'errors' => $this->errors ?: null,
            'data' => $this->data,
        ], static fn ($value) => $value !== null);
    }
}
