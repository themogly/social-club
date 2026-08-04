<?php

namespace App\Http\Controllers\Member;

use App\Actions\Messaging\SendMemberMessage;
use App\Models\MessageThread;
use App\Models\Scopes\OrganisationScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The socio's messages to the club — a threaded conversation, scoped entirely to the authenticated member.
 * There is NO member id in any URL; a thread is resolved by its own ULID AND the member_id ownership check,
 * so one member can never reach another's thread (findOrFail → 404). Not an ordering channel: free text only.
 */
class MessageController extends MemberController
{
    public function index(): View
    {
        return view('socio.messages', [
            'threads' => MessageThread::query()->withoutGlobalScope(OrganisationScope::class)
                ->where('member_id', $this->member()->id)
                ->orderByDesc('last_message_at')
                ->get(),
        ]);
    }

    public function show(string $thread): View
    {
        $thread = $this->resolveOwnThread($thread);

        return view('socio.message', [
            'thread' => $thread,
            'messages' => $thread->messages()->orderBy('created_at')->get(),
        ]);
    }

    public function store(Request $request, SendMemberMessage $action): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $thread = $action->start($this->member(), $data['subject'], $data['body']);

        return redirect()->route('socio.messages.show', ['thread' => $thread->id]);
    }

    public function reply(string $thread, Request $request, SendMemberMessage $action): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $thread = $this->resolveOwnThread($thread);

        $action->append($this->member(), $thread, $data['body']);

        return redirect()->route('socio.messages.show', ['thread' => $thread->id]);
    }

    /** Resolve a thread by id AND ownership — the member guard sets no active org scope, so escape it and pin member_id. */
    private function resolveOwnThread(string $id): MessageThread
    {
        return MessageThread::query()->withoutGlobalScope(OrganisationScope::class)
            ->where('member_id', $this->member()->id)
            ->findOrFail($id);
    }
}
