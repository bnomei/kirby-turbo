<!DOCTYPE html>
<html lang="en">
<head lang="de">
  <meta charset="utf-8">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?= $page->title() ?> | <?= site()->title() ?></title>
</head>
<style>
  /* reset https://www.joshwcomeau.com/css/custom-css-reset/ */
  *, *::before, *::after {
    box-sizing: border-box;
  }

  * {
    margin: 0;
  }

  body {
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
  }

  img, picture, video, canvas, svg {
    display: block;
    max-width: 100%;
  }

  input, button, textarea, select {
    font: inherit;
  }

  p, h1, h2, h3, h4, h5, h6 {
    overflow-wrap: break-word;
  }

  #root, #__next {
    isolation: isolate;
  }

  /* styles */
  body {
    padding: 1rem;
  }

  header {
    display: flex;
    gap: 1rem
  }

  blockquote {
    background: #f9f9f9;
    border-left: 0.25rem solid #ccc;
    margin: 1rem 0;
    padding: 0.5rem;
  }

  ul, ol {
    margin-block-start: 0.25rem;
    margin-block-end: 0.25rem;
    padding-inline-start: 2rem;
  }

  #info {
    position: fixed;
    top: 0;
    right: 0;
    background: #333;
    color: #fff;
    padding: 10px;
    text-align: center;
  }

  #modelCount {
    display: none;
  }

  .filter {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    list-style-type: none;
    margin-bottom: 1rem;
  }

  .filter li {
    background-color: #ddd;
    border: none;
    text-align: center;
    text-decoration: none;
    font-size: 16px;
    padding: 0.25rem 0.5rem;
    cursor: pointer;
    border-radius: 0.25rem;
  }
</style>
<body>

<h1><?= $page->title() ?></h1>
<?= $slots->default() ?>

<div id="info">...</div>
<script>
  // use js to parse the html document headers after the page has loaded and output the headers to the console
  // not the head element, but the headers of the html document response
  window.onload = function () {
    fetch(document.URL, {method: 'HEAD'})
      .then(response => {
        let mc = document.getElementById('modelCount');
        if (mc) {
          mc = mc.innerText + ' models in '
        } else {
          mc = '';
        }
        document.getElementById('info').innerText = mc + response.headers.get('X-Stopwatch-Render');
      })
      .catch(console.error);
  }
</script>

</body>
</html>
