import { useEffect, useState } from 'react'
import './App.css'

type ApiHealth = {
  status: 'checking' | 'ok' | 'unavailable'
  database: 'checking' | 'ok' | 'unavailable'
  service?: string
}

const apiBaseUrl = import.meta.env.VITE_API_BASE_URL || '/api'

function App() {
  const [health, setHealth] = useState<ApiHealth>({
    status: 'checking',
    database: 'checking',
  })

  useEffect(() => {
    const controller = new AbortController()

    fetch(`${apiBaseUrl}/health`, {
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    })
      .then(async (response) => {
        if (!response.ok) throw new Error(`HTTP ${response.status}`)
        return response.json() as Promise<ApiHealth>
      })
      .then(setHealth)
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') return
        setHealth({ status: 'unavailable', database: 'unavailable' })
      })

    return () => controller.abort()
  }, [])

  const ready = health.status === 'ok' && health.database === 'ok'

  return (
    <main className="shell">
      <header className="masthead">
        <div className="brand">RightsRelay</div>
        <span className="environment">開発環境</span>
      </header>

      <section className="hero">
        <p className="eyebrow">INCIDENT RESPONSE DIRECTORY</p>
        <h1>個人情報漏えいの事実と、その後を追う。</h1>
        <p className="lead">
          React、Laravel、PostgreSQLを分離した新しい開発基盤です。
          公開情報の収集とAI判定は、キューワーカーで非同期に処理します。
        </p>
      </section>

      <section className="status-card" aria-live="polite">
        <div>
          <span className={`status-dot ${ready ? 'ready' : ''}`} />
          <strong>{ready ? '開発環境は稼働中です' : 'API接続を確認しています'}</strong>
        </div>
        <dl>
          <div><dt>React</dt><dd>localhost:6544</dd></div>
          <div><dt>Laravel API</dt><dd>{health.status}</dd></div>
          <div><dt>PostgreSQL</dt><dd>{health.database}</dd></div>
          <div><dt>AI</dt><dd>mock（既定）</dd></div>
        </dl>
      </section>

      <section className="flow" aria-labelledby="flow-title">
        <h2 id="flow-title">収集パイプライン</h2>
        <ol>
          <li><span>1</span><div><strong>一次情報を取得</strong><p>企業・団体の公式発表だけを登録済みURLから取得</p></div></li>
          <li><span>2</span><div><strong>本文を抽出</strong><p>HTMLを決定論的に解析し、不要な要素を除去</p></div></li>
          <li><span>3</span><div><strong>AIで構造化</strong><p>件数、対象情報、更新日、対応状況の候補を抽出</p></div></li>
          <li><span>4</span><div><strong>人が確認して公開</strong><p>AIの出力をそのまま公開せず、根拠URLと差分を確認</p></div></li>
        </ol>
      </section>
    </main>
  )
}

export default App
