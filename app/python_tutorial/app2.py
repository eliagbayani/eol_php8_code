# -------------------------------------------------- Queues
from collections import deque
print("-=" * 30)
# Queue is FIFO
queue = deque([])  # create a deque object to implement a queue
queue.append(1)
queue.append(2)
queue.append(3)
print(queue)
queue.popleft()  # a method in deque() to remove the left-most item. e.g. FIFO
print(queue)
if not queue:
    print("Empty")
# -------------------------------------------------- Tuples
"""
Rule of thumb: use lists() if u want your list to change through the course of your usage.
Use tuples if you don't want your values to change after its initial assignment
"""

print("-=" * 30)
point = ()  # parenthesis represents a tuple
print(type(point))
point = 1,
print(type(point))
point = 1, 2
print(type(point))

point = tuple([1, 2])
print(f"type is: {type(point)}")
print(point)

point = tuple("Hello Eli")
print(f"type is: {type(point)}")
print(point)

print("-=" * 30)

point = (1, 2) + (3, 4)
print(point)
point = (1, 2) * 3
print(point)

print("-=" * 30)
point = (1, 2, 3)
print(point[1])
print(point[0:2])
# unpack
x, y, z = point
print(x, y, z)
if 3 in point:
    print("Exists")
else:
    print("does not exist")

# -------------------------------------------------- Arrays [] --- continue to app3.py
print("-=" * 30)
