// Lists the test hosts with this page's own port, so the links work whatever port is used.
(function () {
  var port = location.port ? ":" + location.port : "";
  var pages = [
    ["aozora-zakka.localhost", "情報あり・バッジ「2」（あおぞら雑貨店（架空）：対策を公表／調査中）"],
    ["www.aozora-zakka.localhost", "同じ店舗の別名として登録済み・バッジ「2」"],
    ["kumo-books.localhost", "情報あり・バッジ「1」（くも書房（架空）：追加報告を確認できず）"],
    ["hoshi-zakka.localhost", "登録情報なし・バッジなし"],
    ["shop.aozora-zakka.localhost", "登録情報なし（部分一致しないことの確認）・バッジなし"],
    ["fail-check.localhost", "取得失敗・バッジなし（登録情報なしとは表示しない）"],
  ];
  document.getElementById("host").textContent = location.hostname;
  var body = document.getElementById("links");
  pages.forEach(function (page) {
    var row = document.createElement("tr");
    var link = document.createElement("a");
    link.href = location.protocol + "//" + page[0] + port + "/";
    link.textContent = page[0] + port;
    var cell = document.createElement("td");
    cell.append(link);
    var expect = document.createElement("td");
    expect.textContent = page[1];
    row.append(cell, expect);
    body.append(row);
  });
})();
