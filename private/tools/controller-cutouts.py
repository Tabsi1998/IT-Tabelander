"""Build transparent controller cutouts from the original product photos.

The source photos remain untouched. Cropping and background removal are deliberately
deterministic so product geometry and details are never regenerated.
"""

from collections import deque
from pathlib import Path
from array import array

from PIL import Image, ImageFilter


ROOT = Path(__file__).resolve().parents[2]
ASSETS = ROOT / "public" / "assets" / "img" / "controller"


def is_connected_background(
    pixel: tuple[int, int, int, int], minimum: int, maximum_spread: int
) -> bool:
    red, green, blue, alpha = pixel
    return alpha == 0 or (
        min(red, green, blue) >= minimum
        and max(red, green, blue) - min(red, green, blue) <= maximum_spread
    )


def cutout(
    source: str,
    target: str,
    crop: tuple[int, int, int, int],
    *,
    minimum: int = 224,
    maximum_spread: int = 26,
    keep_largest_component: bool = False,
) -> None:
    image = Image.open(ASSETS / source).convert("RGBA").crop(crop)
    width, height = image.size
    pixels = image.load()
    visited = bytearray(width * height)
    queue: deque[tuple[int, int]] = deque()

    def enqueue(x: int, y: int) -> None:
        index = y * width + x
        if visited[index] or not is_connected_background(pixels[x, y], minimum, maximum_spread):
            return
        visited[index] = 1
        queue.append((x, y))

    for x in range(width):
        enqueue(x, 0)
        enqueue(x, height - 1)
    for y in range(height):
        enqueue(0, y)
        enqueue(width - 1, y)

    while queue:
        x, y = queue.popleft()
        if x > 0:
            enqueue(x - 1, y)
        if x + 1 < width:
            enqueue(x + 1, y)
        if y > 0:
            enqueue(x, y - 1)
        if y + 1 < height:
            enqueue(x, y + 1)

    foreground = bytearray(1 if not value else 0 for value in visited)
    if keep_largest_component:
        component_visited = bytearray(width * height)
        largest = array("I")
        for start in range(width * height):
            if not foreground[start] or component_visited[start]:
                continue
            component = array("I", [start])
            component_visited[start] = 1
            cursor = 0
            while cursor < len(component):
                index = component[cursor]
                cursor += 1
                x = index % width
                y = index // width
                neighbours = []
                if x > 0:
                    neighbours.append(index - 1)
                if x + 1 < width:
                    neighbours.append(index + 1)
                if y > 0:
                    neighbours.append(index - width)
                if y + 1 < height:
                    neighbours.append(index + width)
                for neighbour in neighbours:
                    if foreground[neighbour] and not component_visited[neighbour]:
                        component_visited[neighbour] = 1
                        component.append(neighbour)
            if len(component) > len(largest):
                largest = component
        foreground = bytearray(width * height)
        for index in largest:
            foreground[index] = 1

    alpha = Image.new("L", image.size, 0)
    alpha_pixels = alpha.load()
    for y in range(height):
        row = y * width
        for x in range(width):
            if foreground[row + x]:
                alpha_pixels[x, y] = 255

    alpha = alpha.filter(ImageFilter.GaussianBlur(0.55))
    image.putalpha(alpha)
    bounds = alpha.getbbox()
    if bounds is None:
        raise RuntimeError(f"No foreground found in {source}")

    padding = 18
    left = max(0, bounds[0] - padding)
    top = max(0, bounds[1] - padding)
    right = min(width, bounds[2] + padding)
    bottom = min(height, bounds[3] + padding)
    image = image.crop((left, top, right, bottom))
    image.thumbnail((740, 560), Image.Resampling.LANCZOS)
    image.save(ASSETS / target, optimize=True, compress_level=9)


def main() -> None:
    cutout(
        "upgrade-rise4.jpg",
        "upgrade-rise4-cutout.png",
        (0, 0, 1800, 1800),
        keep_largest_component=True,
    )
    cutout("upgrade-spark-oled.jpg", "upgrade-spark-oled-cutout.png", (80, 585, 1740, 1795))
    cutout("upgrade-beyond-edge.jpg", "upgrade-beyond-edge-cutout.png", (0, 0, 2000, 1460))


if __name__ == "__main__":
    main()
