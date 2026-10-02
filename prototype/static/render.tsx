import { renderToString } from "react-dom/server";
import MorashiterApp from "../app/morashiter-app";

export function render() {
  return renderToString(<MorashiterApp />);
}
