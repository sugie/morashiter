import { renderToStaticMarkup, renderToString } from "react-dom/server";
import MorashiterApp from "../app/morashiter-app";
import { incidents } from "../app/morashiter-data";

export { incidents };

export function render() {
  return renderToString(<MorashiterApp />);
}

/** Official source links for visitors without JavaScript; kept outside #root so hydration is unaffected. */
export function renderNoscript() {
  return renderToStaticMarkup(
    <noscript>
      <ul>
        {incidents.map(i => (
          <li key={i.id} data-incident-id={i.id}>
            {i.name}（{i.date}）：<a href={i.source} target="_blank" rel="noopener noreferrer">公式発表</a>
          </li>
        ))}
      </ul>
    </noscript>,
  );
}
