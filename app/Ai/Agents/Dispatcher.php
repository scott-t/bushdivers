<?php

namespace App\Ai\Agents;

use App\Ai\Tools\FindAirport;
use App\Models\User;
use App\Services\Airports\GetMetarForAirport;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

class Dispatcher implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function __construct(protected User $user)
    {
        $user->load(['location', 'latestPirep.depAirport', 'latestPirep.tour', 'latestPirep.rental', 'latestPirep.aircraft']);
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $sysPrompt =
'You are the contract dispatcher for Bush Divers, a virtual airline. Your role is to assist the user in finding contracts to fly.

Persona: Bush Divers is a virtual airline that operates in various locations, originally out of the Papua New Guinea region. We have a very laid-back and relaxed approach to flying, and specialize in bush operations.

Do not respond beyond the scope of your role as a contract dispatcher. If you are unsure about something, ask the user for clarification.
';

        $name = $this->user->discord_username;
        if ($name && !empty($name)) {
            if (($pos = strpos($name, '#')) !== false) {
                $name = substr($name, 0, $pos);
            }
        } else {
            $name = $this->user->private_name;
        }

        $userDetails = "The user is named {$name} and is currently located at {$this->user->location->name} ({$this->user->location->identifier}).";

        $latestPirep = $this->user->latestPirep;
        if ($latestPirep && $latestPirep->depAirport) {
            $aircraft = $latestPirep->is_rental ? $latestPirep->rental : $latestPirep->aircraft;
            $aircraft->load('fleet');

            $userDetails .= " Their latest flight was {$latestPirep->updated_at->diffForHumans()} from {$latestPirep->depAirport->name} ({$latestPirep->depAirport->identifier}) flying a {$aircraft->fleet->manufacturer} {$aircraft->fleet->name}.";

            $weather = app(GetMetarForAirport::class)->execute($latestPirep->depAirport->identifier);
            if (!empty($weather) && isset($weather['temperature']['celsius'])) {
                $temperature = $weather['temperature']['celsius'];
                $metar = $weather['raw_text'] ?? 'N/A';
                $userDetails .= " The weather near {$latestPirep->depAirport->name} is currently {$temperature}°C (METAR: {$metar}).";
            }
        }

        return $sysPrompt . "\n\n" . $userDetails;
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            new FindAirport,
        ];
    }
}
