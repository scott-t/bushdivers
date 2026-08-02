<?php

namespace App\Http\Controllers\Contracts\Experimental;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ShowContractGeneratorController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $latestConversation = $user->conversations()->latest()->first();
        $latestPirep = $user->pireps()->latest()->first();

        $conversationMessages = [];
        $conversationLocked = false;

        if ($latestConversation) {
            $conversationMessages = $latestConversation->messages()
                ->whereIn('role', ['user', 'assistant'])
                ->orderBy('created_at')
                ->get(['role', 'content', 'created_at'])
                ->toArray();

            $conversationLocked = $latestPirep
                && $latestPirep->created_at > $latestConversation->created_at;
        }

        return Inertia::render('Contract/ContractGenerator', [
            'latestConversation' => $latestConversation,
            'conversationLocked' => $conversationLocked,
            'conversationMessages' => $conversationMessages,
        ]);
    }
}
