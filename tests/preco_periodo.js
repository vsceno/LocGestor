// Usado por tests/funcional.php: calcula LG.precoPeriodo (assets/js/app.js) para cada linha
// [dias, diaria, semanal, mensal] do arquivo JSON recebido e devolve a lista de preços em JSON.
'use strict';
const fs = require('fs');
const path = require('path');

const fonte = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'app.js'), 'utf8');
const LG = new Function('document', 'window', fonte + '\n;return LG;')({ addEventListener() {} }, {});
const linhas = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
process.stdout.write(JSON.stringify(linhas.map(([d, a, b, c]) => LG.precoPeriodo(d, a, b, c))));
