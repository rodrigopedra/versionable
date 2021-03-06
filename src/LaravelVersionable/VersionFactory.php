<?php

namespace RodrigoPedra\LaravelVersionable;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Request;
use RodrigoPedra\LaravelVersionable\Exceptions\InvalidActionForVersionException;
use RodrigoPedra\LaravelVersionable\Exceptions\NoActionForVersionException;
use RodrigoPedra\LaravelVersionable\Traits\HasAction;

class VersionFactory
{
    use HasAction;

    const ACTION_CREATE      = 'create';
    const ACTION_UPDATE      = 'update';
    const ACTION_DELETE      = 'delete';
    const ACTION_SOFT_DELETE = 'soft-delete';
    const ACTION_RESTORE     = 'restore';

    /**
     * @var Versionable
     */
    private $versionable;

    public function __construct( Versionable $versionable )
    {
        $this->versionable = $versionable;
    }

    /**
     * Set the action being performed
     *
     * @param string $action
     *
     * @return $this
     */
    public function setAction( string $action )
    {
        if (!in_array( $action, [
            self::ACTION_CREATE,
            self::ACTION_UPDATE,
            self::ACTION_DELETE,
            self::ACTION_SOFT_DELETE,
            self::ACTION_RESTORE,
        ] )) {
            throw new InvalidActionForVersionException( 'The action for this version informed is invalid' );
        }

        $this->action = $action;

        return $this;
    }

    /**
     * Resets the current action being performed
     *
     * @return $this
     */
    public function resetAction()
    {
        $this->action = null;

        return $this;
    }

    /**
     * Check if an action is set
     *
     * @return bool
     */
    public function hasAction()
    {
        return !is_null( $this->action );
    }

    /**
     * Create a new model's version
     *
     * @return Version|null
     */
    public function createNewVersion()
    {
        if (!$this->hasAction()) {
            throw new NoActionForVersionException( 'An action should be set before creating a new version' );
        }

        if (Event::dispatch( new CreatingVersion( $this->versionable, $this->action ) ) === false) {
            $this->resetAction();

            return null;
        }

        if (!$this->versionable->shouldCreateNewVersion()) {
            $this->resetAction();

            return null;
        }

        /** @var Version $version */
        $version = $this->versionable->versions()->create( [
            'user_id'         => $this->getAuthUserId(),
            'action'          => $this->action,
            'reason'          => $this->versionable->getVersioningReason(),
            'url'             => $this->getRequestUrl(),
            'ip_address'      => $this->getRequestIp(),
            'user_agent'      => $this->getRequestUserAgent(),
            'model_data'      => $this->versionable->serializedAttributesForVersioning(),
            'additional_data' => $this->versionable->serializedAdditionalDataForVersioning(),
        ] );

        Event::dispatch( new CreatedVersion( $this->versionable, $this->action ) );

        $this->resetAction();

        return $version;
    }

    /**
     * Purge model's related versions
     *
     * @return $this
     */
    public function purgeVersions()
    {
        $this->versionable->versions()->delete();

        $this->resetAction();

        return $this;
    }

    /**
     * @return int|null
     */
    private function getAuthUserId()
    {
        if (Auth::check()) {
            return Auth::id();
        }

        return null;
    }

    /**
     * @return string
     */
    private function getRequestUrl()
    {
        if (App::runningInConsole()) {
            return 'console';
        }

        return Request::fullUrl();
    }

    /**
     * @return string|null
     */
    private function getRequestIp()
    {
        if (App::runningInConsole()) {
            return null;
        }

        return Request::ip();
    }

    /**
     * @return string|null
     */
    private function getRequestUserAgent()
    {
        if (App::runningInConsole()) {
            return null;
        }

        return Request::header( 'User-Agent' );
    }
}
