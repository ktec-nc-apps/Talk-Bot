# Talk-Bot

**Chat with an AI assistant right inside Nextcloud Talk, powered by AI-Hub.**
**Nextcloud Talk の中で AI アシスタントと会話できるボット（応答は AI-Hub が担当）。**

> One small PHP app. No external daemon, no webhook endpoint, no Docker, no separate machine. The AI service, key and model live in **AI-Hub**, the shared gateway the whole Base series uses.
> 数百KBのPHPアプリ1つ。外部デーモンもWebhookの受け口もDockerも別マシンも不要。AI サービス・キー・モデルは、Base シリーズ共通のゲートウェイ **AI-Hub** にあります。

[English ↓](#english) · [日本語 ↓](#japanese)

---

## Screenshots

| | |
|---|---|
| ![Chat](screenshots/01-chat.png) | ![Settings](screenshots/02-settings.png) |
| Answering in a Talk conversation<br>Talk の会話で応答しているところ | Talk-Bot's own settings<br>Talk-Bot 側の設定 |

---

<a id="english"></a>

## English

Talk-Bot turns any Nextcloud Talk conversation into an AI chat. It runs as a Talk
in-app bot, entirely inside Nextcloud — a few hundred kilobytes of PHP, installed
from the App Store like any other app. There is no separate service to install and
keep running, and it answers off the request path, so posting a message never
waits for the model.

### Requires AI-Hub

Talk-Bot no longer connects to a model itself. The AI service, key, model and
limits live in **AI-Hub** — a separate app that is the one place AI is set up for
the whole Base series — and Talk-Bot asks it for every answer. Install AI-Hub from
the App Store, choose the AI service, key and model in its settings, and Talk-Bot
uses them. The same AI-Hub also serves the other apps, so there is only one place
to configure and one bill to watch. Until AI-Hub is installed and set up, the bot
replies with how to do it rather than answering.

### A plain, sandboxed assistant

Everyone talks to the same assistant: it answers questions and nothing more. It has
no tools of any kind — no files, no shell, no network, no way to touch the server.
An administrator who wants a chat assistant with shell access uses **NetBase's
assistant** instead; Talk-Bot does not do that.

### And the rest

- **Per-conversation memory.** Every user keeps their own history in every room. Optionally forget it a set number of days after the last message (default: kept until `?reset`).
- **Fair use.** Limit questions per user per minute and how many answers are worked on at once, per user and in total.
- **Commands** — `?help`, `?reset`, `?status`, `?model`, and more.
- **Access control.** Optionally restrict the bot to an allow-list of users.
- **Reply language.** Answer always in a fixed language, or mirror the user.

### Requirements

- Nextcloud 31 – 35 with the **Talk** app (Talk 21 or later: Nextcloud 30 / Talk 20 cannot run bots of this kind)
- The **AI-Hub** app, installed and set up with an AI service, key and model
- PHP 8.1 or newer

### Setup

1. Install and enable the app.
2. Install and enable **AI-Hub**, then open **Administration settings → AI-Hub**
   and choose the AI service, key and model.
3. In **Administration settings → Talk-Bot**, set the reply language, any extra
   instructions and who may use the bot (all optional).
4. In any conversation, a moderator opens **Conversation settings → Bots** and
   switches **Talk-Bot** on.
5. Write a message. The bot reacts with 💭 while it thinks and then answers.

### How it answers without blocking the chat

Talk calls in-app bots while the sender's message is still being posted, so the
model is not called there. The bot hands the work to a second request addressed
to this server, stops waiting for it after two seconds, and that request asks
AI-Hub for the answer and posts it through Talk's bot API when the model is done.
If the server cannot reach itself over HTTP, the work falls back to a background
job instead, and the answer arrives with the next cron run.

### Privacy and security

- Messages sent to the bot go through AI-Hub to the AI provider configured there,
  and nowhere else. Point AI-Hub at a local model server and nothing leaves the
  machine.
- Talk-Bot holds no API key of its own; the key lives in AI-Hub, encrypted in the
  Nextcloud configuration and never sent back to the browser.
- The bot has no tools at all: it cannot read files, run commands or reach anything
  on your server.

### Commands

| Command | Effect |
|---|---|
| `?help` | Show the command list |
| `?reset` | Forget this conversation and start over |
| `?undo` | Remove the last exchange |
| `?retry` | Answer the last question again |
| `?summary` | Summarize the conversation so far |
| `?lang <code>` | Set the reply language for this conversation (`?lang off` to follow each user) |
| `?status` | Show the AI-Hub connection and how much is remembered |
| `?model` | Show the model AI-Hub answers with (set in AI-Hub) |
| `?update` | Where to manage the AI service and model (in AI-Hub) |
| `?whoami` | Show who you are |
| `?version` | Show the app version |
| `?roll`, `?flip`, `?8ball`, `?ping`, `?joke` | For fun |

---

<a id="japanese"></a>

## 日本語

Talk-Bot は、Nextcloud Talk の会話をそのまま AI チャットにするアプリです。
Talk のアプリ内ボットとして、数百KBの PHP だけで Nextcloud の中だけで動きます。
App Store から普通のアプリと同じように入れるだけで、別に動かし続けるサービスは
ありません。応答はリクエストの外で作るので、メッセージの送信が生成完了まで
待たされることもありません。

### AI-Hub が必要です

Talk-Bot はもう自分でモデルに接続しません。AI サービス・キー・モデル・制限は、
Base シリーズ全体で AI をまとめて設定する別アプリ **AI-Hub** にあり、Talk-Bot は
応答のたびに AI-Hub に問い合わせます。AI-Hub を App Store からインストールし、その
設定で AI サービス・キー・モデルを選べば、Talk-Bot はそれを使って応答します。同じ
AI-Hub はほかのアプリでも使えるので、設定する場所も、気にする請求もひとつにまとまります。
AI-Hub が入って設定されるまで、ボットは答える代わりにその手順を返します。

### 素のサンドボックスなアシスタント

全員が同じアシスタントと話します。質問に答えるだけで、ツールは一切ありません——
ファイルも、シェルも、ネットワークも、サーバーに触れる手段もありません。シェルを
使えるチャットアシスタントが必要な管理者は、代わりに **NetBase のアシスタント** を
お使いください。Talk-Bot はそれを行いません。

### その他

- **会話ごとの記憶。** 各ユーザーが各ルームで自分の履歴を保持します。最後の発言から決めた日数で忘れるようにもできます（既定は `?reset` まで残す）。
- **使いすぎの防止。** 1 人あたり 1 分間の質問数と、同時に作成中の回答の数（1 人あたり・全員合わせて）を制限できます。
- **コマンド** — `?help`、`?reset`、`?status`、`?model` など。
- **利用制限。** 許可ユーザーの一覧で利用者を絞れます。
- **返答の言語。** 常に特定の言語で返す／利用者に合わせる、を選べます。

### 動作条件

- Nextcloud 31 〜 35 と **Talk** アプリ（Talk 21 以降。Nextcloud 30 / Talk 20 ではこの形のボットは動きません）
- **AI-Hub** アプリ（インストール済みで、AI サービス・キー・モデルを設定済み）
- PHP 8.1 以降

### 設定手順

1. アプリをインストールして有効化します。
2. **AI-Hub** をインストールして有効化し、**管理者設定 → AI-Hub** で AI サービス・
   キー・モデルを選びます。
3. **管理者設定 → Talk-Bot** で、返答言語・追加指示・利用できるユーザーを設定します
   （いずれも任意）。
4. 使いたい会話で、モデレーターが **会話の設定 → ボット** を開き、**Talk-Bot** を
   オンにします。
5. メッセージを書くと、考えている間は 💭 が付き、その後に返答が届きます。

### チャットを止めずに応答する仕組み

Talk はアプリ内ボットを「送信者のメッセージを投稿している最中」に呼び出すため、
そこでモデルを呼ぶことはしません。ボットは自サーバー宛の2つ目のリクエストに処理を
渡し、2秒待って待機をやめます。そのリクエストは AI-Hub に答えを求め、モデルの応答が
出た時点で Talk のボットAPI経由で返答を投稿します。サーバーが自分自身にHTTPで到達
できない場合は、バックグラウンドジョブに退避し、次のcron実行で返答が届きます。

### プライバシーとセキュリティ

- ボットに送ったメッセージは、AI-Hub を通じて、そこで設定した AI プロバイダーにのみ
  送信されます。AI-Hub でローカルのモデルサーバーを指定すれば、データはサーバーの外に
  出ません。
- Talk-Bot 自身は API キーを持ちません。キーは AI-Hub にあり、Nextcloud の設定に
  暗号化して保存され、ブラウザーへ返されることはありません。
- ボットはツールを一切持ちません。ファイルの読み取り、コマンドの実行、サーバー上の
  リソースへの到達はできません。

### コマンド

| コマンド | 動作 |
|---|---|
| `?help` | コマンド一覧を表示します |
| `?reset` | この会話を忘れて最初からやり直します |
| `?undo` | 直前のやり取りを削除します |
| `?retry` | 直前の質問に答え直します |
| `?summary` | ここまでの会話を要約します |
| `?lang <コード>` | この会話の返答言語を設定します（`?lang off` で各ユーザーの言語に従う） |
| `?status` | AI-Hub の接続状況と記憶量を表示します |
| `?model` | AI-Hub が応答に使うモデルを表示します（設定は AI-Hub 側） |
| `?update` | AI サービスとモデルの設定場所（AI-Hub）を表示します |
| `?whoami` | あなたが誰かを表示します |
| `?version` | アプリのバージョンを表示します |
| `?roll`・`?flip`・`?8ball`・`?ping`・`?joke` | お楽しみ |

---

[AGPL-3.0-or-later](LICENSE) · © KTEC
