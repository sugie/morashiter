import { hydrateRoot } from "react-dom/client";
import MorashiterApp from "../app/morashiter-app";
import "./reset.css";

hydrateRoot(document.getElementById("root")!, <MorashiterApp />);
