# Talk Bot / トークボット

**Chat with Claude, Gemini or any OpenAI-compatible model right inside Nextcloud Talk.**
**Nextcloud Talk の中で Claude / Gemini / OpenAI互換モデルと会話できるボット。**

> Enable the app, add an API key, switch the bot on in a conversation. No external daemon, no webhook endpoint, no shell access.
> アプリを有効化し、APIキーを入れ、会話でボットをオンにするだけ。外部デーモンもWebhookの受け口もシェルへのアクセスも不要です。

[English ↓](#english) · [日本語 ↓](#japanese)

---

## Screenshots

| | |
|---|---|
| ![Chat](screenshots/01-chat.png) | ![Settings](screenshots/02-settings.png) |
| Answering in a Talk conversation<br>Talk の会話で応答しているところ | Admin settings<br>管理設定 |
| ![Model picker](screenshots/03-models.png) | |
| Model list and connection test<br>モデル一覧と接続テスト | |

---

<a id="english"></a>

## English

Talk Bot turns any Nextcloud Talk conversation into an AI chat. It runs as a Talk
in-app bot, entirely inside Nextcloud — there is no separate service to install
and keep running.

### Features

- **Bring your own model.** Anthropic Claude, Google Gemini, or any
  OpenAI-compatible endpoint: OpenRouter, DeepSeek, Qwen/DashScope, Mistral,
  Groq, OpenAI itself, or a local server such as Ollama, vLLM or LM Studio.
- **Pick the model from a list.** The settings page fetches the models your key
  can actually use, so there is nothing to type by hand — and only one place to
  set it.
- **Connection test.** One button checks that the selected engine, key and model
  really answer, before your users try it.
- **Per-conversation memory.** Every user keeps their own history in every room.
- **Slash commands** — `/help`, `/reset`, `/status`.
- **Access control.** Optionally restrict the bot to an allow-list of users.
- **Reply language.** Answer always in a fixed language, or mirror the user.
- **Optional local CLI engine.** If a Claude or Gemini command line tool is
  installed on the server, the bot can drive it instead of an API key. Off by
  default.

### Requirements

- Nextcloud 30 – 32 with the **Talk** app
- PHP 8.1 or newer
- An API key for the service you choose, or a command line tool on the server

### Setup

1. Install and enable the app.
2. Go to **Administration settings → Talk Bot**, choose the AI service and enter
   the API key, then save.
3. In the panel below the form, press **Load models**, choose a model, press
   **Use this model**, and then **Test connection**.
4. In any conversation, a moderator opens **Conversation settings → Bots** and
   switches **Talk Bot** on.
5. Write a message. The bot reacts with 💭 while it thinks and then answers.

### How it answers without blocking the chat

Talk calls in-app bots while the sender's message is still being posted, so the
model is not called there. The bot hands the work to a second request addressed
to this server, stops waiting for it after two seconds, and that request posts
the answer through Talk's bot API when the model is done. If the server cannot
reach itself over HTTP, the work falls back to a background job instead, and the
answer arrives with the next cron run.

### Privacy and security

- Messages sent to the bot go to the AI provider you configured, and nowhere
  else. Point it at a local model server and nothing leaves the machine.
- API keys and the bot secret are stored encrypted in the Nextcloud
  configuration, and are never sent back to the browser.
- The bot has no tools: it cannot read files, run commands or reach anything on
  your server. The optional command line engine runs with all tools disabled.

### Commands

| Command | Effect |
|---|---|
| `/help` | Show the command list |
| `/reset` | Forget this conversation and start over |
| `/status` | Show the engine, model and how much is remembered |

---

<a id="japanese"></a>

## 日本語

トークボットは、Nextcloud Talk の会話をそのまま AI チャットにするアプリです。
Talk のアプリ内ボットとして Nextcloud の中だけで動くので、別途インストールして
動かし続けるサービスはありません。

### 特長

- **好きなモデルを使えます。** Anthropic Claude、Google Gemini、そして OpenAI互換の
  エンドポイント（OpenRouter・DeepSeek・Qwen/DashScope・Mistral・Groq・OpenAI 本家、
  Ollama や vLLM、LM Studio などのローカルサーバー）。
- **モデルは一覧から選べます。** 設定画面がキーで実際に使えるモデルを取得するので、
  手で打つ必要はなく、指定する場所も1か所だけです。
- **接続テスト。** 選んだエンジン・キー・モデルが本当に応答するかを、利用者より先に
  ボタン1つで確認できます。
- **会話ごとの記憶。** 各ユーザーが各ルームで自分の履歴を保持します。
- **スラッシュコマンド** — `/help`、`/reset`、`/status`。
- **利用制限。** 許可ユーザーの一覧で利用者を絞れます。
- **返答の言語。** 常に特定の言語で返す／利用者に合わせる、を選べます。
- **ローカルCLIエンジン（任意）。** サーバーに Claude や Gemini のコマンドラインツールが
  入っていれば、APIキーの代わりにそれを使えます。既定では無効です。

### 動作条件

- Nextcloud 30 〜 32 と **Talk** アプリ
- PHP 8.1 以降
- 選んだサービスのAPIキー、またはサーバー上のコマンドラインツール

### 設定手順

1. アプリをインストールして有効化します。
2. **管理者設定 → トークボット** で AI サービスを選び、APIキーを入力して保存します。
3. フォームの下のパネルで **モデルを取得** → モデルを選択 → **このモデルを使う** →
   **接続テスト** の順に実行します。
4. 使いたい会話で、モデレーターが **会話の設定 → ボット** を開き、**Talk Bot** を
   オンにします。
5. メッセージを書くと、考えている間は 💭 が付き、その後に返答が届きます。

### チャットを止めずに応答する仕組み

Talk はアプリ内ボットを「送信者のメッセージを投稿している最中」に呼び出すため、
そこでモデルを呼ぶことはしません。ボットは自サーバー宛の2つ目のリクエストに処理を
渡し、2秒待って待機をやめます。そのリクエストは、モデルの応答が出た時点で Talk の
ボットAPI経由で返答を投稿します。サーバーが自分自身にHTTPで到達できない場合は、
バックグラウンドジョブに退避し、次のcron実行で返答が届きます。

### プライバシーとセキュリティ

- ボットに送ったメッセージは、設定したAIプロバイダーにのみ送信されます。ローカルの
  モデルサーバーを指定すれば、データはサーバーの外に出ません。
- APIキーとボットのシークレットは Nextcloud の設定に暗号化して保存され、ブラウザーへ
  返されることはありません。
- ボットはツールを一切持ちません。ファイルの読み取り、コマンドの実行、サーバー上の
  リソースへの到達はできません。任意のコマンドラインエンジンも、全ツールを無効にして
  実行します。

### コマンド

| コマンド | 動作 |
|---|---|
| `/help` | コマンド一覧を表示します |
| `/reset` | この会話を忘れて最初からやり直します |
| `/status` | エンジン・モデル・記憶量を表示します |

---

[AGPL-3.0-or-later](LICENSE) · © KTEC
