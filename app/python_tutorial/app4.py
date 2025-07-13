""" ways to create diff objects in Python
list()              x = [] -> an empty list
tuple()             x = () -> an empty tuple
set()               x = set() -> an empty set
dict()              x = {} -> an empty dictionary
"""
# -------------------------------------------------- Generator Expressions
from pprint import pprint
from sys import getsizeof
print("-=" * 30)
"""
Use generator expressions instead of list expressions as much as possible. Former consumes less memory
"""
values = [x*2 for x in range(100000)
          ]  # sample of list comprehension expression
print(type(values))  # values is a list
print("list: ", getsizeof(values))
# for x in values:
#     print(x)
# sample of generator comprehension expression
values = (x*2 for x in range(100000))
print(type(values))  # values is a generator object
print("generator: ", getsizeof(values))
# for x in values:
#     print(x)

# -------------------------------------------------- Unpacking Operator
print("-=" * 30)
numbers = [1, 2, 3]  # a list of numbers
print(numbers)
print(1, 2, 3)  # a list of numbers
print(*numbers)  # using the unpacking operator: *
# sample 2
values = list(range(5))
print(values)
values = [*range(5),  *"Hello"]
print(values)
# sample 3
first = [1, 2]
second = [3]
combined = [*first, "a", *second]
print(combined)
# sample 4: unpacking dictionary
first = {"x": 1}
second = {"x": 10, "y": 2}
combined = {**first, **second, "z": 6}
print(combined)
# if same keys e.g. here "x". The last key will remain

# find the most repeated char in this text
text = "This is a common interview question"
list = [*text]
values = {char: 0 for char in list}
for char in list:
    values[char] = values[char]+1
print(values)

char_sorted = sorted(values.items(), key=lambda kv: kv[1], reverse=True)
print(f"by Eli: {char_sorted[0]}")

# same problem, sol'n by Mosh Hamedani
# text = "This is a common interview question"
char_frequency = {}  # empty dictionary
for char in text:
    if char in char_frequency:
        char_frequency[char] += 1
    else:
        char_frequency[char] = 1

char_sorted = sorted(char_frequency.items(),
                     key=lambda kv: kv[1], reverse=True)
pprint(char_sorted)
print(f"by Mosh: {char_sorted[0]}")

# -------------------------------------------------- Handling Exeptions
print("-=" * 30)
# try:
#     age = int(input("Age: "))
# except ValueError as ex:
#     print("You didn't enter a valid page.")
#     print(ex)
#     print(type(ex))
# else:
#     print("No exceptions were thrown.")
# print("Execution continues")

# -------------------------------------------------- Handling Different Exeptions
print("-=" * 30)
# try:
#     file = open("comment.txt", "w+")
#     age = int(input("Age: "))
#     xfactor = 10/age
# except (ValueError, ZeroDivisionError) as ex:
#     print("You didn't enter a valid page.")
#     print(ex)
#     print(type(ex))
# else:
#     print("No exceptions were thrown.")
# finally:  # this is executed whether there is an exception or not
#     file.close()
# print("Execution continues")
# -------------------------------------------------- The With Statement: you wouldn't need finally: if you use the With statement
print("-=" * 30)
try:
    # sample of openning 2 files:
    # with open("comment.txt", "w+") as file, open("another.txt") as target_file:

    with open("comment.txt", "w+") as file:
        print("File opened")
        """
        if u use the With statement in openning a file. Python will automatically run file.close() So we don't need finally: here.
        if an object has these magic methods, then u can use the With statement. And this object supports "Context Management Protocol"
            file.__enter__
            file.__exit__
        """
    age = int(input("Age: "))
    xfactor = 10/age
except (ValueError, ZeroDivisionError) as ex:
    print("You didn't enter a valid page.")
    print(ex)
    print(type(ex))
else:
    print("No exceptions were thrown.")
print("Execution continues")
