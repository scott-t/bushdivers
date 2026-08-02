<?php

namespace App\Http\Controllers\Contracts\Experimental;

use App\Ai\Agents\Dispatcher;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChatContractGeneratorRequest;
use Illuminate\Support\Facades\Auth;

class ChatContractGeneratorController extends Controller
{
    public function __invoke(ChatContractGeneratorRequest $request)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $dispatcher = new Dispatcher($user);

        $conversationId = $request->input('conversation_id');

        if ($conversationId) {
            $dispatcher->continue($conversationId, $user);
        } else {
            $dispatcher->forUser($user);
        }

        $response = $dispatcher->prompt($request->input('message'), provider: config('services.ai.dispatch.provider'), model: config('services.ai.dispatch.model'));

        return response()->json([
            'conversation_id' => $response->conversationId,
            'message' => $response->text,
        ]);
    }
}