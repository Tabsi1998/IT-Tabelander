import qrcode from "qrcode-generator";

/** The modules of a QR code for `text`, row by row (#72). Level M repairs up
 *  to 15 % of the code - enough for a label that gets scuffed in the shop. */
export function qrModules(text, level = "M") {
  const code = qrcode(0, level);
  code.addData(text);
  code.make();
  const size = code.getModuleCount();
  return Array.from({ length: size }, (_, row) => Array.from({ length: size }, (__, column) => code.isDark(row, column)));
}

/** All dark modules as one SVG path, a run of neighbours per rectangle,
 *  shifted by the quiet zone a scanner needs around the code. */
export function qrPath(modules, quiet = 4) {
  let path = "";
  modules.forEach((row, y) => {
    let x = 0;
    while (x < row.length) {
      if (!row[x]) {
        x += 1;
        continue;
      }
      let run = 1;
      while (x + run < row.length && row[x + run]) run += 1;
      path += `M${x + quiet} ${y + quiet}h${run}v1h-${run}z`;
      x += run;
    }
  });
  return path;
}
