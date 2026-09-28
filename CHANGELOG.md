# Changelog

All notable changes to Talk-Bot.

## 2.1.0 — 2026-09-29

Switch the model and update Claude Code from the chat.
（チャットからモデルの切り替えと Claude Code の更新ができるようになった。）

### New

- **`?model <name or number>` switches the model** (administrators only). Before switching, the
  bot sends the model one short test message and switches only if an answer comes back; a name
  Claude Code does not accept is refused with its reason, and the model stays as it was.
  （**`?model <名前か番号>` でモデルを切り替える**（管理者のみ）。切り替える前に短い試しの一言を送り、
  答えが返ったときだけ切り替える。Claude Code が受け付けない名前は理由を添えて断り、モデルはそのまま。）
- **The short names `fable`, `opus`, `sonnet`, `haiku`** can be used; they always mean the newest
  model of that kind, and the reply says which model actually answers.
  （**短い名前 `fable`・`opus`・`sonnet`・`haiku`** が使える。それぞれの種類の最新モデルを指し、
  実際に答えるモデルを返事に添える。）
- **`?update` updates the Claude Code the bot uses** (administrators only). The bot says it is
  checking, then posts the old and new version (or that it is up to date) and any models the new
  version adds. Only one update runs at a time.
  （**`?update` でボットの使う Claude Code を更新する**（管理者のみ）。「確かめています」と返し、
  前後の版（または最新であること）と、新しく使えるモデルを投稿する。更新は同時に一つだけ。）

### Fixed

- **`?model` now lists the models the installed Claude Code knows**, read from Claude Code itself.
  The list was written into the app and had fallen behind: `claude-sonnet-5-5` was missing, and
  `?update` would have made it older still.
  （**`?model` は、入っている Claude Code が知っているモデルを一覧にする**。Claude Code 自体から読み取る。
  これまでは一覧をアプリに書き込んでいて古くなっており、`claude-sonnet-5-5` が出なかった。）
- **`?model` is a command again.** It was not registered as one, so the model answered it in
  its own words instead of showing the model in use.
  （**`?model` がコマンドとして動く**。コマンドとして登録されておらず、モデルが文章で答えていた。）
- The list of models for the Claude API was brought up to date.
  （Claude API 用のモデル一覧を最新にした。）

## 2.0.7 — 2026-09-25

A release of fixes. The whole app was reviewed, and everything the review found is fixed here.
Some of the fixes close security gaps, so **updating is recommended**.
（修正の版。アプリ全体を点検し、見つかったものをすべて直した。安全に関わる直しを含むので、
**更新を勧める**。）

### Security

- **API keys can no longer appear in a conversation.** A network error from Gemini could
  include the key in the message the bot posted; keys are now removed from every error the bot
  reports, and a failed answer posts a short notice instead of the provider's error text.
  （API キーが会話に出ない。Gemini の通信エラーで、キーを含む文がそのまま投稿されることがあった。
  ボットが知らせるすべてのエラーからキーを取り除き、失敗したときはプロバイダーのエラー文ではなく
  短い知らせを出す。）
- **Ordinary users are sandboxed with the Gemini command line tool too**; before, they could
  read files on the server.
  （Gemini の CLI でも、一般ユーザーはサンドボックスで動く。これまでは、サーバーのファイルを読めた。）
- **Administrator tools only work where nobody else reads the answer**: an administrator's
  one-to-one conversation with the bot's own account (new setting **The bot's own Talk
  account**), or a conversation they are alone in.
  （管理者用のツールは、ほかの人に答えが見えない所でだけ動く。ボット用アカウントとの 1 対 1 の
  会話（新しい設定 **ボット用の Talk アカウント**）か、管理者ひとりだけの会話。）
- A signed request can no longer be replayed, and a first message that starts with `-` is no
  longer read as a command-line option.
  （署名付きの要求を再送できない。`-` で始まる最初の投稿を、CLI のオプションとして読まない。）

### New settings

- **Questions per user per minute** (default 10), **answers in progress at once, per user**
  (default 2) and **for everyone together** (default 4). 0 means no limit.
  （**1 人あたり 1 分間に受け付ける質問の数**（既定 10）、**1 人あたりの同時に作成中の回答の数**（既定 2）、
  **全員合わせて同時に作成中の回答の数**（既定 4）。0 は無制限。）
- **Days to keep a conversation's history** — forgotten this many days after its last message.
  The default, 0, keeps it until `?reset`, as before.
  （**会話の履歴を残す日数** — 最後の発言からこの日数で忘れる。既定の 0 は、これまでどおり
  `?reset` まで残す。）

### Fixed

- **Nextcloud 31 or later is now required.** On Nextcloud 30 (Talk 20) this kind of bot cannot
  run at all, so it is no longer offered there.
  （**Nextcloud 31 以降が必要になった。** Nextcloud 30（Talk 20）ではこの形のボットが動かないため、
  対象から外した。）
- Long messages (over about 650 Japanese characters) no longer make Talk fail to send them;
  long conversations no longer exceed the command line's argument limit.
  （長い投稿（日本語で約 650 文字超）で Talk の送信が失敗しない。長い会話でも CLI の引数の上限を超えない。）
- Whether an answer was handed over is decided correctly, so an answer is neither lost nor
  posted twice. On PHP 8.1/8.2 the command line tool's exit code is read correctly, and a timed
  out tool leaves no processes behind.
  （答えの引き渡しの成否を正しく判断し、答えを失くしたり二重に投稿したりしない。PHP 8.1/8.2 でも
  CLI の終了コードを正しく読む。時間切れの CLI がプロセスを残さない。）
- Messages written at the same moment no longer lose part of the history; `?lang` is limited
  to the conversation's moderators.
  （同時の投稿で履歴が欠けない。`?lang` は会話のモデレーターだけが変えられる。）
- Disabling or removing the app also removes the bot from Talk.
  （アプリを無効化・削除すると、Talk のボット登録も消える。）
- The command line check in the admin settings ("no path configured", exit code) and
  `?status`'s "Remembered exchanges" are translated; the Japanese wording was reviewed. The app
  description lists the current 15 commands.
  （管理画面の CLI の確認結果（パス未設定・終了コード）と、`?status` の「記憶しているやり取り」を
  翻訳した。日本語の文言を見直した。アプリの説明のコマンド一覧を、今の 15 個に合わせた。）

## 2.0.6 — 2026-09-17

### Changed

- **Nextcloud 35 is now supported. There are no other changes.** The supported range
  is widened from 30–34 to 30–35. The app itself is unchanged from 2.0.5: it was tested
  on Nextcloud 35 against the changes that release makes for apps, and ran without
  modification.
  （**Nextcloud 35 に対応した。それ以外の変更はない。** 対応範囲を 30〜34 から 30〜35 に
  広げた。アプリ本体は 2.0.5 から変わっていない。Nextcloud 35 でアプリ向けに変わった点に
  照らして試験し、修正なしで動くことを確かめた。）
