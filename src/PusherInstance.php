<?php

namespace Pusher;

class PusherInstance
{
    private static ?Pusher $instance = null;
    private static string $app_id = '';
    private static string $secret = '';
    private static string $api_key = '';
    private static array $options = [];

    /**
     * Configure default pusher credentials and options.
     *
     * @param string $api_key
     * @param string $secret
     * @param string $app_id
     * @param array $options
     * @return void
     */
    public static function configure(string $api_key, string $secret, string $app_id, array $options = []): void
    {
        self::$api_key = $api_key;
        self::$secret = $secret;
        self::$app_id = $app_id;
        self::$options = $options;
        self::$instance = null;
    }

    /**
     * Set the pusher singleton instance.
     *
     * @param Pusher|null $pusher
     * @return void
     */
    public static function set_pusher(?Pusher $pusher): void
    {
        self::$instance = $pusher;
    }

    /**
     * Reset the singleton instance.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Get the pusher singleton instance.
     *
     * @return Pusher
     * @throws PusherException
     */
    public static function get_pusher(): Pusher
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::$instance = new Pusher(
            self::$api_key,
            self::$secret,
            self::$app_id,
            self::$options
        );

        return self::$instance;
    }
}
